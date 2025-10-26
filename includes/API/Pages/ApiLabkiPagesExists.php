<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Pages;

use ApiBase;
use ApiMain;
use LabkiPackManager\Services\WikiPageLookup;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API endpoint to check if one or more wiki pages exist.
 *
 * ## Purpose
 * Provides an efficient batch query to check page existence and metadata
 * (page ID and namespace) in a single API call. Designed to replace multiple
 * individual page lookups for better performance.
 *
 * ## Action
 * `labkiPagesExists`
 *
 * ## Example Requests
 *
 * Single page:
 * ```
 * api.php?action=labkiPagesExists&titles=Main Page&format=json
 * ```
 *
 * Multiple pages:
 * ```
 * api.php?action=labkiPagesExists&titles=Main Page|Help:Contents|Template:Example&format=json
 * ```
 *
 * ## Example Response
 * ```json
 * {
 *   "results": {
 *     "Safety_Protocol": {
 *       "exists": true,
 *       "page_id": 42,
 *       "namespace": 0
 *     },
 *     "Equipment_List": {
 *       "exists": false
 *     }
 *   },
 *   "meta": {
 *     "schemaVersion": 1,
 *     "timestamp": "20251024120000"
 *   }
 * }
 * ```
 *
 * ## Implementation Notes
 * - Uses {@see WikiPageLookup::getInfo()} to fetch canonical title and existence.
 * - Skips invalid titles rather than failing the entire request.
 * - Returns compact boolean or detailed object per title.
 *
 * @ingroup API
 */
final class ApiLabkiPagesExists extends ApiBase {

	/**
	 * Constructor.
	 *
	 * @param ApiMain $main Main API object.
	 * @param string $name Module name.
	 */
	public function __construct(ApiMain $main, string $name) {
		parent::__construct($main, $name);
	}

	/**
	 * Execute API request.
	 *
	 * Extracts the list of titles and checks each for existence using
	 * WikiPageLookup. Returns a structured JSON response.
	 */
	public function execute(): void {
		$params = $this->extractRequestParams();
		$titles = $params['titles'] ?? [];

		if (empty($titles)) {
			$this->dieWithError(['apierror-missingparam', 'titles'], 'missing_titles');
		}

		$lookup = new WikiPageLookup();
		$results = [];

		foreach ($titles as $title) {
			$title = trim($title);
			if ($title === '') {
				continue;
			}

			$info = $lookup->getInfo($title);
			if ($info === null) {
				// Invalid or malformed title
				continue;
			}

			if (!empty($info['exists'])) {
				$results[$title] = [
					'exists' => true,
					'page_id' => $info['id'] ?? 0,
					'namespace' => $info['namespace'] ?? 0,
				];
			} else {
				$results[$title] = ['exists' => false];
			}
		}

		$result = $this->getResult();
		$result->addValue(null, 'results', $results);
		$result->addValue(null, 'meta', [
			'schemaVersion' => 1,
			'timestamp' => wfTimestampNow(),
		]);
	}

	/**
	 * Define allowed API parameters.
	 *
	 * @return array
	 */
	public function getAllowedParams(): array {
		return [
			'titles' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_REQUIRED => true,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-pages-exists-param-titles',
			],
		];
	}

	/**
	 * Example requests for auto-generated API documentation.
	 *
	 * @return array
	 */
	protected function getExamplesMessages(): array {
		return [
			'action=labkiPagesExists&titles=Main Page'
				=> 'apihelp-labkipagesexists-example-single',
			'action=labkiPagesExists&titles=Main Page|Help:Contents'
				=> 'apihelp-labkipagesexists-example-multiple',
		];
	}

	/**
	 * This API is read-only and can be called via GET.
	 *
	 * @return bool
	 */
	public function isWriteMode(): bool {
		return false;
	}

	/**
	 * Indicates whether the module is internal.
	 * Here it's exposed publicly for automation and tooling.
	 *
	 * @return bool
	 */
	public function isInternal(): bool {
		return false;
	}
}
