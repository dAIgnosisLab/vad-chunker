<?php

namespace VadChunker\Support;

use VadChunker\Exceptions\VadChunkerException;

/**
 * ONNX Runtime shared library. Taken from the onnxruntime-node npm package (it ships the plain C library for
 * each platform), pinned to one version and verified against the registry's sha512 before use.
 */
final class Runtime
{
    public const VERSION = '1.23.0';
    public const TARBALL = 'https://registry.npmjs.org/onnxruntime-node/-/onnxruntime-node-1.23.0.tgz';
    public const INTEGRITY = 'sha512-j7QVuR4ouektZjOopKtXcIGsB3C6R9kVbgS10lc2e5SxoWMUhmCwxNl4qBslpWJaPrFxvrQrQaQLQdqku8V19w==';

    private const MEMBERS = [
        'linux-x64' => 'package/bin/napi-v6/linux/x64/libonnxruntime.so.1',
        'linux-arm64' => 'package/bin/napi-v6/linux/arm64/libonnxruntime.so.1',
        'darwin-x64' => 'package/bin/napi-v6/darwin/x64/libonnxruntime.1.23.0.dylib',
        'darwin-arm64' => 'package/bin/napi-v6/darwin/arm64/libonnxruntime.1.23.0.dylib',
    ];

    public static function platform(): string
    {
        $os = match (PHP_OS_FAMILY) {
            'Linux' => 'linux',
            'Darwin' => 'darwin',
            default => throw new VadChunkerException('Unsupported OS for ONNX Runtime: ' . PHP_OS_FAMILY),
        };
        $arch = match (php_uname('m')) {
            'x86_64', 'amd64' => 'x64',
            'aarch64', 'arm64' => 'arm64',
            default => throw new VadChunkerException('Unsupported CPU for ONNX Runtime: ' . php_uname('m')),
        };
        return "$os-$arch";
    }

    public static function libraryName(): string
    {
        return basename(self::MEMBERS[self::platform()]);
    }

    /** Download, verify and extract the library for this machine to $target. */
    public static function install(string $target): void
    {
        $member = self::MEMBERS[self::platform()];
        $data = @file_get_contents(self::TARBALL, false, stream_context_create(['http' => ['timeout' => 120]]));
        if ($data === false) {
            throw new VadChunkerException('Could not download ' . self::TARBALL);
        }
        $got = 'sha512-' . base64_encode(hash('sha512', $data, true));
        if (!hash_equals(self::INTEGRITY, $got)) {
            throw new VadChunkerException("Integrity check failed for the ONNX Runtime download (got {$got})");
        }

        $tmpDir = sys_get_temp_dir() . '/vad-chunker-ort-' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        try {
            $tgz = "$tmpDir/ort.tgz";
            file_put_contents($tgz, $data);
            (new \PharData($tgz))->extractTo($tmpDir, $member, true);
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            if (!rename("$tmpDir/$member", $target) && !copy("$tmpDir/$member", $target)) {
                throw new VadChunkerException("Could not write {$target}");
            }
            chmod($target, 0644);
        } finally {
            self::rmrf($tmpDir);
        }
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
