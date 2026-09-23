<?php
/**
 * Clés de métas posées sur les versions de travail.
 *
 * @package Lumia\Staging
 */

declare(strict_types=1);

namespace Lumia\Staging\Domain;

defined( 'ABSPATH' ) || exit;

final class Meta {

	public const SOURCE_ID      = '_lmv_source_id';
	public const SOURCE_HASH    = '_lmv_source_hash';
	public const GLOBALS_HASH   = '_lmv_globals_hash';
	public const STATE          = '_lmv_state';
	public const NOTE           = '_lmv_note';
	public const SCHEDULED_AT   = '_lmv_scheduled_at';
	public const BATCH_ID       = '_lmv_batch_id';
	public const BRICKS_VERSION = '_lmv_bricks_version';
	public const ORPHAN         = '_lmv_orphan';
	public const EDITED_AFTER   = '_lmv_modified_after_schedule';
	public const SCHEDULE_NOTE  = '_lmv_schedule_note';

	private function __construct() {}
}
