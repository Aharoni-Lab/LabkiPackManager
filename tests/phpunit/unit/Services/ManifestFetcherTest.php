<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Services {
    use LabkiPackManager\Services\ManifestFetcher;
    use MediaWiki\Status\Status;

    /**
     * @coversDefaultClass \LabkiPackManager\Services\ManifestFetcher
     */
    final class ManifestFetcherTest extends \MediaWikiUnitTestCase {
        /**
         * Build a fetcher with a controllable HTTP factory stub.
         */
        private function newFetcher(HttpFactoryStub $factory): ManifestFetcher {
            return new ManifestFetcher($factory);
        }

        /**
         * Create a temp file with given contents and return its absolute path.
         */
        private function createTempFile(string $contents): string {
            $path = tempnam(sys_get_temp_dir(), 'mf_');
            if ($path === false) {
                $this->fail('tempnam failed');
            }
            file_put_contents($path, $contents);
            return $path;
        }

        /**
         * @covers ::headHash
         */
        public function testHeadHash_LocalFile_ReturnsSha1(): void {
            $path = $this->createTempFile('local-body');
            try {
                $factory = new HttpFactoryStub();
                $fetcher = $this->newFetcher($factory);
                $hash = $fetcher->headHash('file://' . $path);
                $this->assertSame(sha1('local-body'), $hash);
            } finally {
                @unlink($path);
            }
        }

        /**
         * @covers ::headHash
         */
        public function testHeadHash_Remote_UsesETag(): void {
            $factory = new HttpFactoryStub();
            $factory->when('https://ex.test/etag', 'HEAD')->respond(
                ok: true,
                status: 200,
                headers: [ 'etag' => [ '"abc123"' ] ],
                body: ''
            );

            $fetcher = $this->newFetcher($factory);
            $hash = $fetcher->headHash('https://ex.test/etag');
            $this->assertSame('abc123', $hash);
        }

        /**
         * @covers ::headHash
         */
        public function testHeadHash_Remote_UsesLastModified(): void {
            $factory = new HttpFactoryStub();
            $lm = 'Wed, 21 Oct 2015 07:28:00 GMT';
            $factory->when('https://ex.test/lm', 'HEAD')->respond(
                ok: true,
                status: 200,
                headers: [ 'last-modified' => [ $lm ] ],
                body: ''
            );

            $fetcher = $this->newFetcher($factory);
            $hash = $fetcher->headHash('https://ex.test/lm');
            $this->assertSame(sha1($lm), $hash);
        }

        /**
         * @covers ::headHash
         */
        public function testHeadHash_Remote_FallbacksToGETBodyHash(): void {
            $factory = new HttpFactoryStub();
            $factory->when('https://ex.test/no-headers', 'HEAD')->respond(
                ok: true,
                status: 200,
                headers: [],
                body: ''
            );
            $factory->when('https://ex.test/no-headers', 'GET')->respond(
                ok: true,
                status: 200,
                headers: [],
                body: 'body-for-hash'
            );

            $fetcher = $this->newFetcher($factory);
            $hash = $fetcher->headHash('https://ex.test/no-headers');
            $this->assertSame(sha1('body-for-hash'), $hash);
        }

        /**
         * @covers ::headHash
         */
        public function testHeadHash_Remote_HeadFailure_ReturnsNull(): void {
            $factory = new HttpFactoryStub();
            $factory->when('https://ex.test/fail', 'HEAD')->respond(
                ok: false,
                status: 500,
                headers: [],
                body: ''
            );

            $fetcher = $this->newFetcher($factory);
            $hash = $fetcher->headHash('https://ex.test/fail');
            $this->assertNull($hash);
        }

        /**
         * @covers ::fetch
         */
        public function testFetch_LocalFile_Success(): void {
            $path = $this->createTempFile("yaml: true\n");
            try {
                $factory = new HttpFactoryStub();
                $fetcher = $this->newFetcher($factory);
                $status = $fetcher->fetch('file://' . $path);
                $this->assertTrue($status->isOK());
                $this->assertSame("yaml: true\n", $status->getValue());
            } finally {
                @unlink($path);
            }
        }

        /**
         * @covers ::fetch
         */
        public function testFetch_LocalFile_Missing_ReturnsFatal(): void {
            $factory = new HttpFactoryStub();
            $fetcher = $this->newFetcher($factory);
            $status = $fetcher->fetch('/nonexistent/nowhere.yml');
            $this->assertFalse($status->isOK());
        }

        /**
         * @covers ::fetch
         */
        public function testFetch_Remote_Success(): void {
            $factory = new HttpFactoryStub();
            $factory->when('https://ex.test/ok.yml', 'GET')->respond(
                ok: true,
                status: 200,
                headers: [],
                body: "a: b\n"
            );

            $fetcher = $this->newFetcher($factory);
            $status = $fetcher->fetch('https://ex.test/ok.yml');
            $this->assertTrue($status->isOK());
            $this->assertSame("a: b\n", $status->getValue());
        }

        /**
         * @covers ::fetch
         */
        public function testFetch_Remote_EmptyBody_ReturnsFatal(): void {
            $factory = new HttpFactoryStub();
            $factory->when('https://ex.test/empty.yml', 'GET')->respond(
                ok: true,
                status: 200,
                headers: [],
                body: ''
            );

            $fetcher = $this->newFetcher($factory);
            $status = $fetcher->fetch('https://ex.test/empty.yml');
            $this->assertFalse($status->isOK());
        }
    }

    /**
     * Simple HTTP factory/request test doubles.
     */
    final class HttpFactoryStub {
        /** @var array<string, array<string, HttpRequestStub>> */
        private array $map = [];

        public function when(string $url, string $method): HttpRequestStub {
            $method = strtoupper($method);
            $req = new HttpRequestStub();
            $this->map[$url][$method] = $req;
            return $req;
        }

        public function create(string $url, array $opts) {
            $method = strtoupper($opts['method'] ?? 'GET');
            return $this->map[$url][$method] ?? new HttpRequestStub();
        }
    }

    final class HttpRequestStub {
        private bool $ok = true;
        private int $status = 200;
        private array $headers = [];
        private string $body = '';

        public function respond(bool $ok, int $status, array $headers, string $body): self {
            $this->ok = $ok;
            $this->status = $status;
            $this->headers = $headers;
            $this->body = $body;
            return $this;
        }

        public function execute(): Status { return $this->ok ? Status::newGood(null) : Status::newFatal('http-error'); }
        public function getResponseHeaders(): array { return $this->headers; }
        public function getStatus(): int { return $this->status; }
        public function getContent(): string { return $this->body; }
    }
}


