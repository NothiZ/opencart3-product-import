<?php

/** Diagnostic with parameters that may be presented to an administrator. */
class NothizProductImportIssue extends RuntimeException {
    private $details;

    public function __construct(string $code, array $params = [], string $fallback = '') {
        parent::__construct($fallback === '' ? $code : $fallback);
        $this->details = ['code' => $code, 'params' => $params];
    }

    public function issue(): array {
        return $this->details;
    }
}
