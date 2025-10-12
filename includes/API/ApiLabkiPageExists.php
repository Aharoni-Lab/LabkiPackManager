<?php

declare(strict_types=1);

namespace LabkiPackManager\API;

use ApiBase;
use ApiMain;
use LabkiPackManager\Services\WikiPageLookup;

final class ApiLabkiPageExists extends ApiBase {

    public function __construct( ApiMain $main, string $name ) {
        parent::__construct( $main, $name );
    }

    public function execute(): void {
        $params = $this->extractRequestParams();
        $titleText = (string)$params['title'];

        $lookup = new WikiPageLookup();
        $exists = $lookup->exists( $titleText );

        $this->getResult()->addValue(
            null,
            $this->getModuleName(),
            [ 'exists' => (bool)$exists ]
        );
    }

    public function getAllowedParams(): array {
        return [
            'title' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => true,
            ],
        ];
    }

    public function isInternal(): bool {
        return false;
    }
}
