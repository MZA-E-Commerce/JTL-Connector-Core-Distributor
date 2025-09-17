<?php

declare(strict_types=1);

namespace Jtl\Connector\Core\Application;

use Composer\Autoload\ClassLoader;
use DI\Container;
use DI\ContainerBuilder;
use DI\Definition\Exception\InvalidDefinition;
use DI\DependencyException;
use DI\NotFoundException;
use Doctrine\Common\Annotations\AnnotationException;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\DBAL\DriverManager;
use Jawira\CaseConverter\CaseConverterException;
use JMS\Serializer\Exception\LogicException;
use JMS\Serializer\Exception\NotAcceptableException;
use JMS\Serializer\Exception\UnsupportedFormatException;
use JMS\Serializer\Serializer;
use Jtl\Connector\Core\Authentication\TokenValidatorInterface;
use Jtl\Connector\Core\Checksum\ChecksumLoaderInterface;
use Jtl\Connector\Core\Compression\Zip;
use Jtl\Connector\Core\Config\ConfigSchema;
use Jtl\Connector\Core\Config\ConfigSchemaConfigInterface;
use Jtl\Connector\Core\Config\CoreConfigInterface;
use Jtl\Connector\Core\Config\FileConfig;
use Jtl\Connector\Core\Connector\ConnectorInterface;
use Jtl\Connector\Core\Connector\HandleRequestInterface;
use Jtl\Connector\Core\Connector\ModelInterface;
use Jtl\Connector\Core\Connector\UseChecksumInterface;
use Jtl\Connector\Core\Controller\ConnectorController;
use Jtl\Connector\Core\Controller\StatisticInterface;
use Jtl\Connector\Core\Controller\TransactionalInterface;
use Jtl\Connector\Core\Definition\Action;
use Jtl\Connector\Core\Definition\Controller;
use Jtl\Connector\Core\Definition\ErrorCode;
use Jtl\Connector\Core\Definition\Event;
use Jtl\Connector\Core\Definition\Model;
use Jtl\Connector\Core\Definition\RelationType;
use Jtl\Connector\Core\Definition\RpcMethod;
use Jtl\Connector\Core\Error\AbstractErrorHandler;
use Jtl\Connector\Core\Error\ErrorHandler;
use Jtl\Connector\Core\Event\AckEvent;
use Jtl\Connector\Core\Event\BoolEvent;
use Jtl\Connector\Core\Event\ConnectorIdentificationEvent;
use Jtl\Connector\Core\Event\FeaturesEvent;
use Jtl\Connector\Core\Event\ModelEvent;
use Jtl\Connector\Core\Event\QueryFilterEvent;
use Jtl\Connector\Core\Event\RequestEvent;
use Jtl\Connector\Core\Event\ResponseEvent;
use Jtl\Connector\Core\Event\RpcEvent;
use Jtl\Connector\Core\Event\StatisticEvent;
use Jtl\Connector\Core\Exception\ApplicationException;
use Jtl\Connector\Core\Exception\CompressionException;
use Jtl\Connector\Core\Exception\ConfigException;
use Jtl\Connector\Core\Exception\DatabaseException;
use Jtl\Connector\Core\Exception\DefinitionException;
use Jtl\Connector\Core\Exception\FileNotFoundException;
use Jtl\Connector\Core\Exception\LinkerException;
use Jtl\Connector\Core\Exception\LoggerException;
use Jtl\Connector\Core\Exception\RpcException;
use Jtl\Connector\Core\Exception\SessionException;
use Jtl\Connector\Core\Http\JsonResponse as HttpResponse;
use Jtl\Connector\Core\Linker\ChecksumLinker;
use Jtl\Connector\Core\Linker\IdentityLinker;
use Jtl\Connector\Core\Logger\LoggerService;
use Jtl\Connector\Core\Mapper\PrimaryKeyMapperInterface;
use Jtl\Connector\Core\Model\AbstractImage;
use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\Ack;
use Jtl\Connector\Core\Model\Authentication;
use Jtl\Connector\Core\Model\ConnectorIdentification;
use Jtl\Connector\Core\Model\Features;
use Jtl\Connector\Core\Model\Identities;
use Jtl\Connector\Core\Model\IdentityInterface;
use Jtl\Connector\Core\Model\Product;
use Jtl\Connector\Core\Model\QueryFilter;
use Jtl\Connector\Core\Model\Statistic;
use Jtl\Connector\Core\Plugin\PluginInterface;
use Jtl\Connector\Core\Rpc\Error;
use Jtl\Connector\Core\Rpc\Method;
use Jtl\Connector\Core\Rpc\RequestPacket;
use Jtl\Connector\Core\Rpc\ResponsePacket;
use Jtl\Connector\Core\Rpc\Warnings;
use Jtl\Connector\Core\Serializer\SerializerBuilder;
use Jtl\Connector\Core\Session\MySQLSessionHandler;
use Jtl\Connector\Core\Session\SessionHandlerInterface;
use Jtl\Connector\Core\Session\SqliteSessionHandler;
use Jtl\Connector\Core\Subscriber\FeaturesSubscriber;
use Jtl\Connector\Core\Subscriber\RequestParamsTransformSubscriber;
use Jtl\Connector\Core\Utilities\Validator\Validate;
use Jtl\Connector\Dbc\Connection;
use Jtl\Connector\Dbc\DbManager;
use Monolog\ErrorHandler as MonologErrorHandler;
use Monolog\Logger;
use Noodlehaus\Exception\EmptyDirectoryException;
use Psr\Container\ContainerInterface;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Throwable;

class Application
{
    public const PROTOCOL_VERSION = 7;
    public const MIN_PHP_VERSION  = '7.4';
    /** @var array<string, string> */
    protected static array    $mimeTypeToExtensionMappings = [
        'image/bmp'                => 'bmp',
        'image/x-bmp'              => 'bmp',
        'image/x-bitmap'           => 'bmp',
        'image/x-xbitmap'          => 'bmp',
        'image/x-win-bitmap'       => 'bmp',
        'image/x-windows-bmp'      => 'bmp',
        'image/ms-bmp'             => 'bmp',
        'image/x-ms-bmp'           => 'bmp',
        'application/bmp'          => 'bmp',
        'application/x-bmp'        => 'bmp',
        'application/x-win-bitmap' => 'bmp',
        'image/gif'                => 'gif',
        'image/x-icon'             => 'ico',
        'image/x-ico'              => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
        'image/jpeg'               => 'jpg',
        'image/pjpeg'              => 'jpg',
        'image/svg+xml'            => 'svg',
        'image/png'                => 'png',
        'image/x-png'              => 'png',
        'image/tiff'               => 'tif',
        'image/x-tiff'             => 'tif',
    ];
    protected CoreConfigInterface $config;
    protected ConfigSchema        $configSchema;
    protected string              $connectorDir;
    protected Container           $container;
    /** @var string[] */
    protected array                   $deleteFromFileSystem = [];
    protected AbstractErrorHandler    $errorHandler;
    protected EventDispatcher         $eventDispatcher;
    protected Filesystem              $fileSystem;
    protected LoggerService           $loggerService;
    protected HttpRequest             $httpRequest;
    protected HttpResponse            $httpResponse;
    protected SessionHandlerInterface $sessionHandler;
    protected Serializer              $serializer;
    private \PDO $pdo;

    /**
     * Application constructor.
     *
     * @param string                   $connectorDir
     * @param CoreConfigInterface|null $config
     * @param ConfigSchema|null        $configSchema
     *
     * @throws ApplicationException
     * @throws ConfigException
     * @throws DependencyException
     * @throws InvalidArgumentException
     * @throws LoggerException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws InvalidDefinition
     * @throws AnnotationException
     * @throws \InvalidArgumentException
     * @throws \JMS\Serializer\Exception\InvalidArgumentException
     * @throws LogicException
     * @throws \JMS\Serializer\Exception\RuntimeException
     * @throws \LogicException
     * @throws EmptyDirectoryException
     * @throws \TypeError
     * @throws \UnexpectedValueException
     */
    public function __construct(
        string               $connectorDir,
        ?CoreConfigInterface $config       = null,
        ?ConfigSchema        $configSchema = null
    ) {
        if (!\is_dir($connectorDir)) {
            throw ApplicationException::connectorDirNotExists($connectorDir);
        }
        AnnotationRegistry::registerLoader('class_exists');

        if ($configSchema !== null && $config !== null) {
            if ($config instanceof ConfigSchemaConfigInterface) {
                $config->setConfigSchema($configSchema);
            }
        } else {
            $configSchema = $configSchema ?? new ConfigSchema();
            $config       = new FileConfig(\sprintf('%s/config/config.json', $connectorDir), $configSchema);
        }


        $this->prepareConfig($connectorDir, $config, $configSchema);

        $serializerCacheDir = null;
        if (
            $config instanceof CoreConfigInterface
            && $config->getBool(ConfigSchema::DEBUG, false) === false
            && $config->getBool(ConfigSchema::SERIALIZER_ENABLE_CACHE, true) === true
        ) {
            $serializerCacheDir = $config->getString(ConfigSchema::CACHE_DIR);
        }

        $this->connectorDir = $connectorDir;
        $this->config       = $config;
        $this->configSchema = $configSchema;
        $this->container    = (new ContainerBuilder())
            ->useAnnotations(true)
            ->useAutowiring(true)
            ->build();

        $logLevel = Validate::string($this->config->get(ConfigSchema::LOG_LEVEL));
        $this->container->set(__CLASS__, $this);
        $this->eventDispatcher = new EventDispatcher();
        $this->fileSystem      = new Filesystem();
        /** @var Warnings $warnings */
        $warnings            = $this->container->get(Warnings::class);
        $this->loggerService =
            (
            new LoggerService(
                Validate::string($this->config->get(ConfigSchema::LOG_DIR)),
                $logLevel,
                $warnings
            )
            )->setFormat(Validate::string($this->config->get(ConfigSchema::LOG_FORMAT)));

        $this->container->set(LoggerService::class, $this->loggerService);
        /** use a factory to always return the instance from the service, instead of a possible stale reference */
        $this->container->set(
            LoggerInterface::class,
            \DI\factory(
                function (LoggerService $service) {
                    return $service->get(LoggerService::CHANNEL_GLOBAL);
                }
            )->parameter('service', $this->loggerService)
        );

        $this->serializer   = SerializerBuilder::create($serializerCacheDir)->build();
        $this->httpRequest  = HttpRequest::createFromGlobals();
        $this->httpResponse = new HttpResponse($this->eventDispatcher, $this->serializer);
        $this->errorHandler = new ErrorHandler($this->httpResponse);

        $this->pdo = new \PDO(
            \sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                $this->config->get('db.host'),
                $this->config->get('db.name')
            ), $this->config->get('db.username'), $this->config->get('db.password'));
    }

    /**
     * @param string              $connectorDir
     * @param CoreConfigInterface $config
     * @param ConfigSchema        $configSchema
     *
     * @return void
     * @throws ConfigException
     */
    protected function prepareConfig(
        string              $connectorDir,
        CoreConfigInterface $config,
        ConfigSchema        $configSchema
    ): void {
        foreach (ConfigSchema::createDefaultParameters($connectorDir) as $parameter) {
            if ($configSchema->hasParameter($parameter->getKey())) {
                $parameter->setDefaultValue($configSchema->getParameter($parameter->getKey())->getDefaultValue());
            }
            $configSchema->setParameter($parameter);
        }

        $config->set(ConfigSchema::CONNECTOR_DIR, $connectorDir);
        foreach ($configSchema->getParameters() as $parameter) {
            $key = $parameter->getKey();
            if ($parameter->hasDefaultValue() && !$config->has($key)) {
                $config->set($key, $parameter->getDefaultValue());
            }
        }
    }

    /**
     * @param string $controllerName
     * @param object $instance
     *
     * @return $this
     * @throws DefinitionException
     * @throws \LogicException
     */
    public function registerController(string $controllerName, object $instance): self
    {
        if (!Controller::isController($controllerName)) {
            throw DefinitionException::unknownController($controllerName);
        }

        $this->container->set($controllerName, $instance);

        return $this;
    }

    /**
     * @param ConnectorInterface $connector
     *
     * @return void
     * @throws ApplicationException
     * @throws CompressionException
     * @throws ConfigException
     * @throws DefinitionException
     * @throws DependencyException
     * @throws FileNotFoundException
     * @throws NotFoundException
     * @throws RpcException
     * @throws SessionException
     * @throws Throwable
     * @throws \ReflectionException
     */
    public function run(ConnectorInterface $connector): void
    {
        $jtlRpc = Validate::string($this->httpRequest->get('jtlrpc', ''));

        $serverName = $_SERVER['SERVER_NAME'] ?? gethostname();
        if ($serverName == 'jtl-connector.docker') {
            file_put_contents('/var/www/html/var/log/rpc.log', 'API URLS 
                            | Date: ' . date('d.m.Y H:i:s') . ' 
                            | jtlRpc-Data: ' . PHP_EOL . $jtlRpc . PHP_EOL . PHP_EOL, FILE_APPEND);
        } else {
            file_put_contents('/home/www/p689712/html/jtl-connector-haendlershop/var/log/rpc.log', 'API URLS 
                            | Date: ' . date('d.m.Y H:i:s') . ' 
                            | jtlRpc-Data: ' . PHP_EOL . $jtlRpc . PHP_EOL . PHP_EOL, FILE_APPEND);
        }

        $this->httpResponse->setLogger($this->loggerService->get(LoggerService::CHANNEL_RPC));
        $this->eventDispatcher->addSubscriber(new RequestParamsTransformSubscriber());
        $this->eventDispatcher->addSubscriber(new FeaturesSubscriber());
        $this->errorHandler->register();
        MonologErrorHandler::register($this->loggerService->get(LoggerService::CHANNEL_ERROR));
        $requestPacket = RequestPacket::createFromJtlrpc($jtlRpc, $this->serializer);

        #var_dump($requestPacket);die;

        $this->errorHandler->setRequestPacket($requestPacket);
        $responsePacket = null;

        try {
            if (!$requestPacket->isValid()) {
                throw RpcException::invalidRequest();
            }

            $method = Method::createFromRequestPacket($requestPacket);
            if (!Controller::isController($method->getController())) {
                throw DefinitionException::unknownController($method->getController());
            }

            if (!Action::isAction($method->getAction())) {
                throw DefinitionException::unknownAction($method->getAction());
            }

            $this->startSession($requestPacket->getMethod());
            $connector->initialize($this->config, $this->container, $this->eventDispatcher);
            $this->prepareContainer($connector);
            $this->loadPlugins(
                $this->config,
                $this->container,
                $this->eventDispatcher,
                Validate::string($this->config->get(ConfigSchema::PLUGINS_DIR))
            );
            $this->configSchema->validateConfig($this->config);

            $logJtlRpc = $jtlRpc;
            if ($requestPacket->getMethod() === RpcMethod::AUTH) {
                $pattern     = '/\"token\":\s?\"(.*)\"/';
                $replacement = '"token": "******************"';
                $logJtlRpc   = \preg_replace($pattern, $replacement, $logJtlRpc);
            }

            // Log incoming request packet (debug only and configuration must be initialized)
            $this->loggerService->get(LoggerService::CHANNEL_RPC)->debug(Validate::string($logJtlRpc));

            $event = new RpcEvent($requestPacket->getParams(), $method->getController(), $method->getAction());
            $this->eventDispatcher->dispatch($event, Event::createRpcEventName(Event::BEFORE));
            if (!\is_array($data = $event->getData())) {
                throw new \RuntimeException('$data must be an array.');
            }
            $requestPacket->setParams($data);

            $responsePacket = $this->execute($connector, $requestPacket, $method);
            /** @var Warnings $warnings */
            $warnings = $this->container->get(Warnings::class);
            if ($warnings->hasWarnings()) {
                $responsePacket->addWarnings(...$warnings->getWarnings());
            }
            \session_write_close();
        } catch (Throwable $ex) {
            if (\is_numeric($code = $ex->getCode())) {
                $codeInt = (int)$code;
            } else {
                throw new \RuntimeException('exception code must be numeric.');
            }
            $error = (new Error())
                ->setCode($codeInt)
                ->setMessage($ex->getMessage())
                ->setData(Error::createDataFromException($ex));

            /** @var ResponsePacket $responsePacket */
            $responsePacket = ResponsePacket::create($requestPacket->getId());
            $responsePacket->setError($error);

            $this->loggerService->get(LoggerService::CHANNEL_ERROR)->error($ex->getTraceAsString());

            throw $ex;
        } finally {
            $responsePacket = $responsePacket instanceof ResponsePacket
                ? $responsePacket
                : ResponsePacket::create($requestPacket->getId());
            $this->fileSystem->remove($this->deleteFromFileSystem);

            $this->httpResponse->prepareAndSend($requestPacket, Validate::responsePacket($responsePacket));

            if (\random_int(0, 99) === 0) {
                $this->getSessionHandler()->gc((int)\ini_get('session.gc_maxlifetime'));
            }
        }
    }

    /**
     * @param string $rpcMethod
     *
     *
     * @return void
     * @throws DatabaseException
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws SessionException
     * @throws \InvalidArgumentException
     * @throws BadRequestException
     * @throws \UnexpectedValueException
     */
    protected function startSession(string $rpcMethod): void
    {
        $sessionId   = $this->httpRequest->get('jtlauth');
        $sessionName = 'JtlConnector';

        if ($sessionId === null && $rpcMethod !== RpcMethod::AUTH) {
            throw new SessionException('No session', ErrorCode::NO_SESSION);
        }

        $sessionHandler = $this->getSessionHandler();

        $sessionHandler->setLogger($this->loggerService->get(LoggerService::CHANNEL_SESSION));

        \session_name($sessionName);
        if (\is_string($sessionId) && $sessionId !== '') {
            if ($sessionHandler->validateId($sessionId)) {
                \session_id($sessionId);
            } else {
                throw new SessionException('Session is invalid', ErrorCode::INVALID_SESSION);
            }
        }

        \session_set_save_handler($sessionHandler, true);

        \session_start();
        $this->loggerService
            ->get(LoggerService::CHANNEL_SESSION)
            ->debug('Session started with id ({sessionId})', ['sessionId' => \session_id()]);
    }

    /**
     * @return SessionHandlerInterface
     * @throws DatabaseException
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws SessionException
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     * @throws \Exception
     */
    public function getSessionHandler(): SessionHandlerInterface
    {
        if (!isset($this->sessionHandler)) {

            // Different Session Handler implementations
            #$this->sessionHandler = new SqliteSessionHandler(\sprintf('%s/var', $this->connectorDir));
            #$this->sessionHandler = new MySQLSessionHandler($this->pdo);

            $connectionParams = [
                'dbname'   => $this->config->get('db.name'),
                'user'     => $this->config->get('db.username'),
                'password' => $this->config->get('db.password'),
                'host'     => $this->config->get('db.host'),
                'driver'   => 'pdo_mysql',
                'charset'  => 'utf8mb4'
            ];

            $config = new \Doctrine\DBAL\Configuration();
            $conn = new Connection(
                DriverManager::getConnection($connectionParams, $config)->getParams(),
                DriverManager::getConnection($connectionParams, $config)->getDriver(),
                $config
            );
            $dbManager = new DbManager($conn);

            $this->sessionHandler = new \Jtl\Connector\Dbc\Session\SessionHandler($dbManager);
            $this->sessionHandler->setLogger($this->loggerService->get(LoggerService::CHANNEL_SESSION));
        }

        return $this->sessionHandler;
    }

    /**
     * @param SessionHandlerInterface $sessionHandler
     *
     * @return $this
     */
    public function setSessionHandler(SessionHandlerInterface $sessionHandler): self
    {
        $this->sessionHandler = $sessionHandler;

        return $this;
    }

    /**
     * @param ConnectorInterface $connector
     *
     * @return void
     * @throws DatabaseException
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws SessionException
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \UnexpectedValueException
     */
    protected function prepareContainer(ConnectorInterface $connector): void
    {
        $this->container->set(CoreConfigInterface::class, $this->getConfig());
        $this->container->set(SessionHandlerInterface::class, $this->getSessionHandler());
        $this->container->set(TokenValidatorInterface::class, $connector->getTokenValidator());
        $this->container->set(PrimaryKeyMapperInterface::class, $connector->getPrimaryKeyMapper());

        if ($connector instanceof UseChecksumInterface) {
            $this->container->set(ChecksumLoaderInterface::class, $connector->getChecksumLoader());
        }

        $this->container->set(ChecksumLinker::class, function (ContainerInterface $container) {
            $loader = $container->has(ChecksumLoaderInterface::class)
                ? $container->get(ChecksumLoaderInterface::class)
                : null;
            /** @var ChecksumLoaderInterface|null $loader */
            $linker = new ChecksumLinker($loader);
            $linker->setLogger($this->loggerService->get(LoggerService::CHANNEL_CHECKSUM));

            return $linker;
        });

        $this->container->set(IdentityLinker::class, function (ContainerInterface $container) {
            /** @var PrimaryKeyMapperInterface $pkmi */
            $pkmi   = $container->get(PrimaryKeyMapperInterface::class);
            $linker = new IdentityLinker($pkmi);
            $linker->setLogger($this->loggerService->get(LoggerService::CHANNEL_LINKER));

            return $linker;
        });
    }

    /**
     * @return CoreConfigInterface
     */
    public function getConfig(): CoreConfigInterface
    {
        return $this->config;
    }

    /**
     * @param CoreConfigInterface $config
     * @param Container           $container
     * @param EventDispatcher     $eventDispatcher
     * @param string              $pluginsDir
     *
     * @return void
     * @throws DirectoryNotFoundException
     * @throws \TypeError
     */
    protected function loadPlugins(
        CoreConfigInterface $config,
        Container           $container,
        EventDispatcher     $eventDispatcher,
        string              $pluginsDir
    ): void {
        $loader = new ClassLoader();
        $loader->add('', $pluginsDir);
        $loader->register();

        if (\is_dir($pluginsDir)) {
            $finder = (new Finder())->files()->name('/Bootstrap.php/')->in($pluginsDir);

            foreach ($finder as $file) {
                $class = \sprintf(
                    '\\%s\\Bootstrap',
                    \str_replace(\DIRECTORY_SEPARATOR, '\\', $file->getRelativePath())
                );
                if (\class_exists($class)) {
                    $plugin = new $class();
                    if ($plugin instanceof PluginInterface) {
                        $plugin->registerListener($config, $container, $eventDispatcher);
                    }
                }
            }
        }
    }

    /**
     * @param ConnectorInterface $connector
     * @param RequestPacket      $requestPacket
     * @param Method             $method
     *
     * @return ResponsePacket
     * @throws ApplicationException
     * @throws CaseConverterException
     * @throws CompressionException
     * @throws DefinitionException
     * @throws DependencyException
     * @throws FileNotFoundException
     * @throws LinkerException
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws RpcException
     * @throws RuntimeException
     * @throws Throwable
     * @throws \InvalidArgumentException
     */
    protected function execute(
        ConnectorInterface $connector,
        RequestPacket      $requestPacket,
        Method             $method
    ): ResponsePacket {
        $modelNamespace = Model::MODEL_NAMESPACE;
        if ($connector instanceof ModelInterface) {
            $modelNamespace = $connector->getModelNamespace();
        }

        $request = $this->createHandleRequest($requestPacket, $method, $modelNamespace);
        if ($request->getController() === Controller::IMAGE && $request->getAction() === Action::PUSH) {
            /** @var AbstractImage[] $params */
            $params = $request->getParams();
            $this->handleImagePush(...$params);
        }

        $this->eventDispatcher->dispatch(
            new RequestEvent($request),
            Event::createHandleEventName($request->getController(), $request->getAction(), Event::BEFORE)
        );

        if ($connector instanceof HandleRequestInterface && !$method->isCore()) {
            $response = $connector->handle($this, $request);
        } else {
            $response = $this->handleRequest($connector, $request);
        }

        $this->eventDispatcher->dispatch(
            new ResponseEvent($response),
            Event::createHandleEventName($request->getController(), $request->getAction(), Event::AFTER)
        );

        $eventName = null;
        if (Action::isCoreAction($request->getAction())) {
            $eventName = Event::createCoreEventName($request->getController(), $request->getAction(), Event::AFTER);
        } elseif (Action::isAction($request->getAction())) {
            $eventName = Event::createEventName($request->getController(), $request->getAction(), Event::AFTER);
        }

        // Identity mapping
        $resultData = $response->getResult();
        if (!\is_array($resultData)) {
            if (
                !\is_object($resultData)
                && !(
                    \is_bool($resultData)
                    && \in_array(
                        $request->getAction(),
                        [Action::ACK, Action::CLEAR, Action::FINISH, Action::INIT],
                        true
                    )
                )
            ) {
                throw new \RuntimeException('$resultData must be an array|object|bool.');
            }
            $resultData = [$resultData];
        }
        foreach ($resultData as $result) {
            if (
                $connector instanceof HandleRequestInterface
                || \in_array($request->getAction(), [Action::PUSH, Action::DELETE], true) === false
            ) {
                if ($result instanceof AbstractModel) {
                    /** @var IdentityLinker $identityLinker */
                    $identityLinker = $this->container->get(IdentityLinker::class);
                    $identityLinker->linkModel($result, ($request->getAction() === Action::DELETE));
                    /** @var ChecksumLinker $checksumLinker */
                    $checksumLinker = $this->container->get(ChecksumLinker::class);
                    $checksumLinker->link($result);
                }
            }

            $eventArg = null;
            switch ($request->getAction()) {
                case Action::PUSH:
                case Action::PULL:
                case Action::DELETE:
                    $eventArgClass = $this->createModelEventClassName($request->getController());
                    $eventArg      = new $eventArgClass($result);
                    break;
                case Action::STATISTIC:
                    if ($result instanceof Statistic === false) {
                        throw new \RuntimeException('$result must be instance of ' . Statistic::class);
                    }
                    $eventArg = new StatisticEvent($result);
                    break;
                case Action::ACK:
                case Action::CLEAR:
                case Action::FINISH:
                case Action::INIT:
                    $eventArg = new BoolEvent($result);
                    break;
                case Action::IDENTIFY:
                    if ($result instanceof ConnectorIdentification === false) {
                        throw new \RuntimeException('$result must be instance of ' . ConnectorIdentification::class);
                    }
                    $eventArg = new ConnectorIdentificationEvent($result);
                    break;
                case Action::FEATURES:
                    if ($result instanceof Features === false) {
                        throw new \RuntimeException('$result must be instance of ' . Features::class);
                    }
                    $eventArg = new FeaturesEvent($result);
                    break;
            }

            if (!\is_null($eventName) && !\is_null($eventArg)) {
                $this->eventDispatcher->dispatch($eventArg, $eventName);
            }
        }

        return $this->buildRpcResponse($requestPacket, $response);
    }

    /**
     * @param RequestPacket $requestPacket
     * @param Method        $method
     * @param string        $modelNamespace
     *
     * @return Request
     * @throws CaseConverterException
     * @throws DefinitionException
     * @throws DependencyException
     * @throws InvalidArgumentException
     * @throws LinkerException
     * @throws LogicException
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws \InvalidArgumentException
     * @throws NotAcceptableException
     * @throws \JMS\Serializer\Exception\RuntimeException
     * @throws UnsupportedFormatException
     */
    protected function createHandleRequest(
        RequestPacket $requestPacket,
        Method        $method,
        string        $modelNamespace
    ): Request {
        $controller = $method->getController();
        $action     = $method->getAction();

        switch ($action) {
            case Action::AUTH:
                $type = Authentication::class;
                break;
            case Action::ACK:
                $type = Ack::class;
                break;
            case Action::CLEAR:
                $type = Identities::class;
                break;
            case Action::PUSH:
            case Action::DELETE:
                $className = \sprintf('%s\%s', $modelNamespace, $controller);
                switch ($controller) {
                    case Controller::IMAGE:
                        $className = AbstractImage::class;
                        break;
                    case Controller::PRODUCT_PRICE:
                    case Controller::PRODUCT_STOCK_LEVEL:
                        $className = Product::class;
                        break;
                }
                $type = \sprintf('array<%s>', $className);
                break;
            default:
                $type = QueryFilter::class;
        }

        $params = $this->serializer->fromArray($requestPacket->getParams(), $type);

        if (!\is_array($params)) {
            $params = [$params];
        }

        $eventArgClass = $this->createModelEventClassName($controller);

        // Identity mapping
        foreach ($params as $param) {
            $eventArg = null;
            switch ($action) {
                case Action::ACK:
                    $eventArg = new AckEvent($param);
                    break;
                case Action::PUSH:
                case Action::DELETE:
                    /** @var IdentityLinker $identityLinker */
                    $identityLinker = $this->container->get(IdentityLinker::class);
                    $identityLinker->linkModel($param);
                    /** @var ChecksumLinker $checksumLinker */
                    $checksumLinker = $this->container->get(ChecksumLinker::class);
                    $checksumLinker->link($param);
                    $eventArg = new $eventArgClass($param);
                    break;
                case Action::PULL:
                case Action::STATISTIC:
                    $eventArg = new QueryFilterEvent($param);
                    break;
                case Action::CLEAR:
                    foreach ($param->getIdentities() as $relationType => $identities) {
                        foreach ($identities as $identity) {
                            /** @var IdentityLinker $identityLinker */
                            $identityLinker = $this->container->get(IdentityLinker::class);
                            $identityLinker->linkIdentity($identity, RelationType::getModelName($relationType), 'id');
                        }
                    }
                    break;
            }

            if (!\is_null($eventArg)) {
                $eventName = Action::isCoreAction($action)
                    ? Event::createCoreEventName($controller, $action, Event::BEFORE)
                    : Event::createEventName($controller, $action, Event::BEFORE);

                $this->eventDispatcher->dispatch($eventArg, $eventName);
            }
        }

        return Request::create($controller, $action, $params);
    }

    /**
     * @param string $controllerName
     *
     * @return string
     */
    protected function createModelEventClassName(string $controllerName): string
    {
        if (\in_array($controllerName, [Controller::PRODUCT_PRICE, Controller::PRODUCT_STOCK_LEVEL], true)) {
            $controllerName = Controller::PRODUCT;
        }

        $eventArgClass = \sprintf('Jtl\\Connector\\Core\\Event\\%sEvent', $controllerName);
        if (!\class_exists($eventArgClass)) {
            $eventArgClass = ModelEvent::class;
        }

        return $eventArgClass;
    }

    /**
     * @param AbstractImage ...$images
     *
     * @return void
     * @throws ApplicationException
     * @throws CompressionException
     * @throws DefinitionException
     * @throws FileNotFoundException
     * @throws \Exception
     */
    protected function handleImagePush(AbstractImage ...$images): void
    {
        $imagePaths = [];
        if (!\is_scalar($config = $this->config->get(ConfigSchema::CACHE_DIR))) {
            throw new \RuntimeException('$config must be scalar.');
        }
        $tempDir = $this->deleteFromFileSystem[] = \sprintf(
            '%s/%s',
            $config,
            \uniqid('images-', true)
        );
        $this->fileSystem->mkdir($tempDir);

        if ($this->httpRequest->files->has('file')) {
            /** @var UploadedFile $zipFile */
            $zipFile = $this->httpRequest->files->get('file');
            $archive = new Zip();
            if ($archive->extract($zipFile->getRealPath(), $tempDir)) {
                $finder = (new Finder())->files()->ignoreDotFiles(true)->in($tempDir);
                foreach ($finder as $file) {
                    $imagePaths[] = $file->getRealpath();
                }
            } else {
                throw ApplicationException::fileCouldNotGetExtracted();
            }
        }

        foreach ($images as $image) {
            if (!$this->httpRequest->files->has('file')) {
                throw ApplicationException::uploadedFileNotFound();
            }

            $imageFound = false;
            foreach ($imagePaths as $imagePath) {
                $fileInfo                = \pathinfo($imagePath);
                [$hostId, $relationType] = \explode('_', $fileInfo['filename']);
                if (
                    (int)$hostId === $image->getId()->getHost()
                    && \strtolower($relationType) === \strtolower($image->getRelationType())
                ) {
                    if (!\is_string($contentType = \mime_content_type($imagePath))) {
                        throw new \RuntimeException('$contentType must be a string.');
                    }
                    $extension = self::determineExtensionByMimeType($contentType);
                    if (
                        $extension !== null
                        && isset($fileInfo['extension'])
                        && $fileInfo['extension'] !== $extension
                    ) {
                        $newImagePath = \sprintf('%s/%s.%s', $tempDir, $fileInfo['filename'], $extension);
                        \rename($imagePath, $newImagePath);
                        $imagePath = $newImagePath;
                    }

                    $image->setFilename($imagePath);
                    $imageFound = true;
                    break;
                }
            }
            if (!$imageFound) {
                throw ApplicationException::imageNotFound($image);
            }
        }
    }

    /**
     * @param string $mimeType
     *
     * @return string|null
     */
    protected static function determineExtensionByMimeType(string $mimeType): ?string
    {
        return self::$mimeTypeToExtensionMappings[$mimeType] ?? null;
    }

    /**
     * @param ConnectorInterface $connector
     * @param Request            $request
     *
     * @return Response
     * @throws ApplicationException
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \ReflectionException
     * @throws \RuntimeException
     * @throws \Throwable
     */
    public function handleRequest(ConnectorInterface $connector, Request $request): Response
    {
        $controllerName = $request->getController();
        $action         = $request->getAction();
        $params         = $request->getParams();

        if (Action::isCoreAction($action)) {
            $this->container->set($controllerName, function (ContainerInterface $container) {

                if (!\is_string($featuresPath = $this->config->get(ConfigSchema::FEATURES_PATH))) {
                    throw new \RuntimeException('$featuresPath must be a string!');
                }
                /** @var ChecksumLinker $checksumLinker */
                $checksumLinker = $container->get(ChecksumLinker::class);
                /** @var IdentityLinker $identityLinker */
                $identityLinker = $container->get(IdentityLinker::class);
                /** @var SessionHandlerInterface $sessionHandlerInterface */
                $sessionHandlerInterface = $container->get(SessionHandlerInterface::class);
                /** @var TokenValidatorInterface $tokenValidatorInterface */
                $tokenValidatorInterface = $container->get(TokenValidatorInterface::class);

                $controller = new ConnectorController(
                    $featuresPath,
                    $checksumLinker,
                    $identityLinker,
                    $sessionHandlerInterface,
                    $tokenValidatorInterface
                );

                $controller->setLogger($this->loggerService->get(LoggerService::CHANNEL_GLOBAL));

                return $controller;
            });
        } elseif (!$this->container->has($controllerName)) {
            $controllerClass = \sprintf("%s\\%sController", $connector->getControllerNamespace(), $controllerName);
            if (!\class_exists($controllerClass)) {
                throw new ApplicationException(\sprintf('Controller class %s does not exist!', $controllerClass));
            }

            $this->container->set($controllerName, $this->container->get($controllerClass));
        }

        $controller = $this->container->get($controllerName);
        if ($controller instanceof LoggerAwareInterface) {
            /** @var LoggerInterface $loggerInterface */
            $loggerInterface = $this->container->get(LoggerInterface::class);
            $controller->setLogger($loggerInterface);
        }

        $result = [];
        switch ($action) {
            case Action::PUSH:
            case Action::DELETE:
                try {
                    if ($controller instanceof TransactionalInterface) {
                        $controller->beginTransaction();
                    }

                    $dataModels = $controller->$action(...$params);

                    foreach ($dataModels as $dataModel) {
                        if ($dataModel instanceof AbstractModel) {
                            /** @var IdentityLinker $identityLinker */
                            $identityLinker = $this->container->get(IdentityLinker::class);
                            $identityLinker->linkModel($dataModel, ($request->getAction() === Action::DELETE));
                            /** @var ChecksumLinker $checksumLinker */
                            $checksumLinker = $this->container->get(ChecksumLinker::class);
                            $checksumLinker->link($dataModel);
                        }
                        $result[] = $dataModel;

                        if ($controller instanceof TransactionalInterface) {
                            $controller->commit();
                        }
                    }
                } catch (Throwable $ex) {
                    if ($controller instanceof TransactionalInterface) {
                        $controller->rollback();
                    }

                    foreach ($params as $model) {
                        if (!($model instanceof AbstractModel)) {
                            throw new \RuntimeException('$model must be instance of AbstractModel.');
                        }
                        $this->extendExceptionMessageWithIdentifiers($ex, $model, $controllerName, $action);
                    }

                    throw $ex;
                }
                break;
            case Action::IDENTIFY:
                $result = $controller->$action($connector);
                break;
            default:
                $param  = \count($params) > 0 ? \reset($params) : null;
                $result = $controller->$action($param);
                break;
        }

        if ($action === Action::STATISTIC && $controller instanceof StatisticInterface) {
            $result = (new Statistic())
                ->setControllerName($controllerName)
                ->setAvailable((int)$result);
        }

        if (!$result instanceof Response) {
            $result = Response::create($result);
        }

        return $result;
    }

    /**
     * @param \Throwable  $ex
     * @param object|null $model
     * @param string      $controller
     * @param string      $action
     *
     * @return void
     * @throws \ReflectionException
     */
    protected function extendExceptionMessageWithIdentifiers(
        Throwable $ex,
        ?object   $model,
        string    $controller,
        string    $action
    ): void {
        $messages = [
            \sprintf('Controller = %s', $controller),
            \sprintf('Action = %s', $action),
        ];

        if ($model instanceof IdentityInterface && $model->getId()->getHost() > 0) {
            $messages[] = \sprintf('JTL-Wawi PK = %d', $model->getId()->getHost());
        }

        if ($model instanceof AbstractModel) {
            $messages = \array_merge($messages, $model->getIdentificationStrings());
        }

        $messages[]         = $ex->getMessage();
        $reflectionClass    = new ReflectionClass($ex);
        $reflectionProperty = $reflectionClass->getProperty('message');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($ex, \implode(' | ', $messages));
        $reflectionProperty->setAccessible(false);
    }

    /**
     * @param RequestPacket $requestPacket
     * @param Response      $response
     *
     * @return ResponsePacket
     * @throws RpcException
     */
    protected function buildRpcResponse(RequestPacket $requestPacket, Response $response): ResponsePacket
    {
        /** @var ResponsePacket $responsePacket */
        $responsePacket = ResponsePacket::create($requestPacket->getId());
        $responsePacket->setResult($response->getResult());

        if (!$responsePacket->isValid()) {
            throw new RpcException('Parse error', ErrorCode::PARSE_ERROR);
        }

        return $responsePacket;
    }

    /**
     * @return Container
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * @param AbstractErrorHandler $handler
     *
     * @return $this
     */
    public function setErrorHandler(AbstractErrorHandler $handler): Application
    {
        $this->errorHandler = $handler;

        return $this;
    }

    /**
     * @return EventDispatcher
     */
    public function getEventDispatcher(): EventDispatcher
    {
        return $this->eventDispatcher;
    }

    /**
     * @return LoggerService
     */
    public function getLoggerService(): LoggerService
    {
        return $this->loggerService;
    }

    /**
     * @return int
     */
    public function getProtocolVersion(): int
    {
        return self::PROTOCOL_VERSION;
    }

    /**
     * @param HttpRequest $httpRequest
     *
     * @return Application
     */
    public function setHttpRequest(HttpRequest $httpRequest): self
    {
        $this->httpRequest = $httpRequest;

        return $this;
    }

    /**
     * @return Serializer
     */
    public function getSerializer(): Serializer
    {
        return $this->serializer;
    }
}
