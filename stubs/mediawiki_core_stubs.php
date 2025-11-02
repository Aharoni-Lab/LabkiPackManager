<?php
class ApiBase {
    public const PARAM_HELP_MSG = 'param-help-msg';
    public function dieWithError($msg, $code = null) {}
    protected function extractRequestParams(): array {
        return [];
    }
    protected function getUser(): User {
        return new User();
    }
    protected function getTitle(): Title {
        return new Title();
    }
    protected function getResult(): ApiResult {
        return new ApiResult();
    }
    protected function getParameter(string $name): string {
        return '';
    }
    protected function getAuthority(): User {
        return new User();
    }
}
class ApiMain {}
class WANObjectCache {
    public const TTL_INDEFINITE = 0;
}
abstract class Job {
    protected array $params;
    public function __construct(string $command, Title $title, array $params = []) {
        $this->params = $params;
    }
    abstract public function run(): bool;
}
function wfDebugLog(string $channel, string $message): void {}
function wfMessage(string $key): string { return ''; }
