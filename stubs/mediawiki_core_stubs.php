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
}
class ApiMain {}
function wfDebugLog(string $channel, string $message): void {}
function wfMessage(string $key): string { return ''; }
