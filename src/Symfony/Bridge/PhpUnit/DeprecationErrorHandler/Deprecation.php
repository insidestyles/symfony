<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\PhpUnit\DeprecationErrorHandler;

use Symfony\Bridge\PhpUnit\Legacy\SymfonyTestsListenerFor;

/**
 * @internal
 */
class Deprecation
{
    /**
     * @var array
     */
    private $trace;

    /**
     * @var string
     */
    private $message;

    /**
     * @var ?string
     */
    private $originClass;

    /**
     * @var ?string
     */
    private $originMethod;

    /**
     * @var bool
     */
    private $self;

    /** @var string[] absolute paths to vendor directories */
    private static $vendors;

    /**
     * @param string $message
     * @param string $file
     */
    public function __construct($message, array $trace, $file)
    {
        $this->trace = $trace;
        $this->message = $message;
        $i = \count($trace);
        while (1 < $i && $this->lineShouldBeSkipped($trace[--$i])) {
            // No-op
        }
        $line = $trace[$i];
        $this->self = !$this->pathOriginatesFromVendor($file);
        if (isset($line['object']) || isset($line['class'])) {
            if (isset($line['class']) && 0 === strpos($line['class'], SymfonyTestsListenerFor::class)) {
                $parsedMsg = unserialize($this->message);
                $this->message = $parsedMsg['deprecation'];
                $this->originClass = $parsedMsg['class'];
                $this->originMethod = $parsedMsg['method'];
                // If the deprecation has been triggered via
                // \Symfony\Bridge\PhpUnit\Legacy\SymfonyTestsListenerTrait::endTest()
                // then we need to use the serialized information to determine
                // if the error has been triggered from vendor code.
                $this->self = isset($parsedMsg['triggering_file'])
                    && $this->pathOriginatesFromVendor($parsedMsg['triggering_file']);

                return;
            }
            $this->originClass = isset($line['object']) ? \get_class($line['object']) : $line['class'];
            $this->originMethod = $line['function'];
        }
    }

    /**
     * @return bool
     */
    private function lineShouldBeSkipped(array $line)
    {
        if (!isset($line['class'])) {
            return true;
        }
        $class = $line['class'];

        return 'ReflectionMethod' === $class || 0 === strpos($class, 'PHPUnit_') || 0 === strpos($class, 'PHPUnit\\');
    }

    /**
     * @return bool
     */
    public function originatesFromAnObject()
    {
        return isset($this->originClass);
    }

    /**
     * @return bool
     */
    public function isSelf()
    {
        return $this->self;
    }

    /**
     * @return string
     */
    public function originatingClass()
    {
        if (null === $this->originClass) {
            throw new \LogicException('Check with originatesFromAnObject() before calling this method');
        }

        return $this->originClass;
    }

    /**
     * @return string
     */
    public function originatingMethod()
    {
        if (null === $this->originMethod) {
            throw new \LogicException('Check with originatesFromAnObject() before calling this method');
        }

        return $this->originMethod;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @param string $utilPrefix
     *
     * @return bool
     */
    public function isLegacy($utilPrefix)
    {
        $test = $utilPrefix.'Test';
        $class = $this->originatingClass();
        $method = $this->originatingMethod();

        return 0 === strpos($method, 'testLegacy')
            || 0 === strpos($method, 'provideLegacy')
            || 0 === strpos($method, 'getLegacy')
            || strpos($class, '\Legacy')
            || \in_array('legacy', $test::getGroups($class, $method), true);
    }

    /**
     * Tells whether both the calling package and the called package are vendor
     * packages.
     *
     * @return bool
     */
    public function isIndirect()
    {
        $erroringFile = $erroringPackage = null;
        foreach ($this->trace as $line) {
            if (\in_array($line['function'], ['require', 'require_once', 'include', 'include_once'], true)) {
                continue;
            }
            if (!isset($line['file'])) {
                continue;
            }
            $file = $line['file'];
            if ('-' === $file || 'Standard input code' === $file || !realpath($file)) {
                continue;
            }
            if (!$this->pathOriginatesFromVendor($file)) {
                return false;
            }
            if (null !== $erroringFile && null !== $erroringPackage) {
                $package = $this->getPackage($file);
                if ('composer' !== $package && $package !== $erroringPackage) {
                    return true;
                }
                continue;
            }
            $erroringFile = $file;
            $erroringPackage = $this->getPackage($file);
        }

        return false;
    }

    /**
     * pathOriginatesFromVendor() should always be called prior to calling this method.
     *
     * @param string $path
     *
     * @return string
     */
    private function getPackage($path)
    {
        $path = realpath($path) ?: $path;
        foreach (self::getVendors() as $vendorRoot) {
            if (0 === strpos($path, $vendorRoot)) {
                $relativePath = substr($path, \strlen($vendorRoot) + 1);
                $vendor = strstr($relativePath, \DIRECTORY_SEPARATOR, true);
                if (false === $vendor) {
                    throw new \RuntimeException(sprintf('Could not find directory separator "%s" in path "%s"', \DIRECTORY_SEPARATOR, $relativePath));
                }

                return rtrim($vendor.'/'.strstr(substr(
                    $relativePath,
                    \strlen($vendor) + 1
                ), \DIRECTORY_SEPARATOR, true), '/');
            }
        }

        throw new \RuntimeException(sprintf('No vendors found for path "%s"', $path));
    }

    /**
     * @return string[] an array of paths
     */
    private static function getVendors()
    {
        if (null === self::$vendors) {
            self::$vendors = [];
            foreach (get_declared_classes() as $class) {
                if ('C' === $class[0] && 0 === strpos($class, 'ComposerAutoloaderInit')) {
                    $r = new \ReflectionClass($class);
                    $v = \dirname(\dirname($r->getFileName()));
                    if (file_exists($v.'/composer/installed.json')) {
                        self::$vendors[] = $v;
                    }
                }
            }
        }

        return self::$vendors;
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    private function pathOriginatesFromVendor($path)
    {
        $realPath = realpath($path);
        if (false === $realPath && '-' !== $path && 'Standard input code' !== $path) {
            return true;
        }
        foreach (self::getVendors() as $vendor) {
            if (0 === strpos($realPath, $vendor) && false !== strpbrk(substr($realPath, \strlen($vendor), 1), '/'.\DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string
     */
    public function toString()
    {
        $exception = new \Exception($this->message);
        $reflection = new \ReflectionProperty($exception, 'trace');
        $reflection->setAccessible(true);
        $reflection->setValue($exception, $this->trace);

        return 'deprecation triggered by '.$this->originatingClass().'::'.$this->originatingMethod().':'.
        "\n".$this->message.
        "\nStack trace:".
        "\n".str_replace(' '.getcwd().\DIRECTORY_SEPARATOR, ' ', $exception->getTraceAsString()).
        "\n";
    }

    private function getPackageFromLine(array $line)
    {
        if (!isset($line['file'])) {
            return 'internal function';
        }
        if (!$this->pathOriginatesFromVendor($line['file'])) {
            return 'source code';
        }
        try {
            return $this->getPackage($line['file']);
        } catch (\RuntimeException $e) {
            return 'unknown';
        }
    }
}
