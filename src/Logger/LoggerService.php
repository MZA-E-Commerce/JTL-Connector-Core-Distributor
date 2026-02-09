<?php

declare(strict_types=1);

namespace Jtl\Connector\Core\Logger;

use DI\Container;
use Jtl\Connector\Core\Exception\LoggerException;
use Jtl\Connector\Core\Logger\Handler\ChunkedHandler;
use Jtl\Connector\Core\Logger\Processor\RequestProcessor;
use Jtl\Connector\Core\Logger\Processor\WarningProcessor;
use Jtl\Connector\Core\Rpc\Warnings;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\FilterHandler;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger as MonoLogger;
use Monolog\Processor\MemoryPeakUsageProcessor;
use Monolog\Processor\ProcessorInterface;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LogLevel;
use Psr\Log\InvalidArgumentException;
use ReflectionException;
use RuntimeException;
use UnexpectedValueException;

class LoggerService
{
    public const
        CHANNEL_CHECKSUM = 'checksum',
        CHANNEL_ERROR    = 'error',
        CHANNEL_GLOBAL   = 'global',
        CHANNEL_LINKER   = 'linker',
        CHANNEL_RPC      = 'rpc',
        CHANNEL_SESSION  = 'session',
        CHANNEL_ENDPOINT = 'endpoint';

    /** @var MonoLogger[] */
    protected array $channels = [];

    /** @var ProcessorInterface[] */
    protected array $processors = [];

    protected FormatterInterface $formatter;
    protected string             $logDir;
    protected string|int|LogLevel $logLevel;
    protected int                 $maxFiles = 2;
    // Final handler that is wrapped by FilterHandler

    protected HandlerInterface   $handler;
    // Handler that writes to combined log file

    protected HandlerInterface   $combinedHandler;
    protected bool $useChunkedHandler = false;

    /**
     * LoggerFactory constructor.
     *
     * @param string     $logDir
     * @param int|string $logLevel
     * @param Warnings   $warnings
     * @param int        $maxFiles
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws UnexpectedValueException|\InvalidArgumentException
     */
    public function __construct(string $logDir, int|string $logLevel, Warnings $warnings, int $maxFiles = 2)
    {
        $this->logDir   = $logDir;
        $this->logLevel = $logLevel;
        $this->maxFiles = $maxFiles;
        $this
            ->pushProcessor(new WarningProcessor($warnings))
            ->pushProcessor(new PsrLogMessageProcessor())
            ->pushProcessor(new MemoryPeakUsageProcessor())
            ->pushProcessor(new RequestProcessor());

        $fileName              = \sprintf('%s/combined.log', $this->logDir);
        $this->combinedHandler = new RotatingFileHandler($fileName, $this->maxFiles, MonoLogger::DEBUG);

        $this->createHandler();
    }

    /**
     * @return $this
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function useChunkedHandler(): self
    {
        $this->useChunkedHandler = true;
        if (!$this->combinedHandler instanceof ChunkedHandler) {
            $this->combinedHandler = new ChunkedHandler($this->combinedHandler);
            $this->createHandler();
        }
        return $this;
    }

    /**
     * @param int|string $logLevel
     *
     * @return void
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function setLogLevel(int|string $logLevel): void
    {
        $this->logLevel = $logLevel;
        $this->createHandler();
        // need to close all channels to update the handler
        foreach ($this->channels as $channel) {
            $channel->close();
        }
        $this->channels = [];
    }

    /**
     * (re-)created the handler for the combined log file
     *
     * @return void
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    protected function createHandler(): void
    {
        // needed if we change the level
        if (isset($this->handler)) {
            $this->handler->close();
        }
        $logLevel = MonoLogger::toMonologLevel($this->logLevel); // @phpstan-ignore-line
        $handler  = new FilterHandler($this->combinedHandler, $logLevel);
        if (isset($this->formatter)) {
            $handler->setFormatter($this->formatter);
        }
        $this->handler = $handler;
    }

    /**
     * @param ProcessorInterface $processor
     *
     * @return $this
     */
    public function pushProcessor(ProcessorInterface $processor): self
    {
        if (\in_array($processor, $this->processors, true)) {
            return $this;
        }

        foreach ($this->channels as $channel) {
            $channel->pushProcessor($processor);
        }

        $this->processors[] = $processor;

        return $this;
    }

    /**
     * creates legacy handler for each channel
     *
     * @param string           $channel
     * @param int|string|Level $logLevel
     *
     * @return HandlerInterface
     * @throws InvalidArgumentException
     * @throws UnexpectedValueException
     * @throws \InvalidArgumentException
     */
    protected function createChannelSpecificHandler(string $channel, int|string|Level $logLevel): HandlerInterface
    {
        $fileName     = \sprintf('%s/%s.log', $this->logDir, $channel);
        $monologLevel = MonoLogger::toMonologLevel($logLevel); // @phpstan-ignore-line
        $handler      = new RotatingFileHandler($fileName, $this->maxFiles, $monologLevel);
        if ($this->useChunkedHandler) {
            $handler = new ChunkedHandler($handler);
        }
        if (
            isset($this->formatter)
            && ($handler instanceof FormattableHandlerInterface // @phpstan-ignore-line
            || \method_exists($handler, 'setFormatter'))
        ) {
            $handler->setFormatter($this->formatter);
        }
        return $handler;
    }

    /**
     * @param string $channel
     *
     * @return MonoLogger
     * @throws InvalidArgumentException
     * @throws UnexpectedValueException
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function get(string $channel): MonoLogger
    {
        $channel = \lcfirst($channel);
        if (!$this->has($channel)) {
            $this->channels[$channel] = new MonoLogger($channel);
        }

        $logLevel = MonoLogger::toMonologLevel($this->logLevel); // @phpstan-ignore-line
        if (!$this->channels[$channel]->isHandling($logLevel)) {
            $handler = $this->createChannelSpecificHandler($channel, $logLevel);
            $this->channels[$channel]->pushHandler($this->handler);
            $this->channels[$channel]->pushHandler($handler);
            foreach ($this->processors as $processor) {
                $this->channels[$channel]->pushProcessor($processor);
            }
        }

        return $this->channels[$channel];
    }

    /**
     * @param string $channel
     *
     * @return bool
     */
    public function has(string $channel): bool
    {
        return isset($this->channels[\lcfirst($channel)]);
    }

    /**
     * @param FormatterInterface $formatter
     *
     * @return $this
     */
    public function setFormatter(FormatterInterface $formatter): self
    {
        foreach ($this->channels as $channel) {
            foreach ($channel->getHandlers() as $handler) {
                if (
                    $handler instanceof FormattableHandlerInterface
                    || \method_exists($handler, 'setFormatter')
                ) {
                    $handler->setFormatter($formatter);
                }
            }
        }
        if (
            $this->combinedHandler instanceof FormattableHandlerInterface
            || \method_exists($this->combinedHandler, 'setFormatter')
        ) {
            $this->combinedHandler->setFormatter($formatter);
        }

        $this->formatter = $formatter;

        return $this;
    }

    /**
     * @param string               $format
     * @param array{}|array<mixed> $arguments
     *
     * @return $this
     * @throws LoggerException
     * @throws RuntimeException
     * @throws ReflectionException
     */
    public function setFormat(string $format, array $arguments = []): self
    {
        $formatterClass = \sprintf('Monolog\Formatter\%sFormatter', \ucfirst($format));
        if (!\class_exists($formatterClass)) {
            throw LoggerException::formatterNotExists($formatterClass);
        }
        $formatter = (new \ReflectionClass($formatterClass))->newInstanceArgs($arguments);
        if (!($formatter instanceof FormatterInterface)) {
            throw new \RuntimeException('Formatter ' . $formatterClass . ' not found.');
        }
        $this->setFormatter($formatter);

        return $this;
    }
}
