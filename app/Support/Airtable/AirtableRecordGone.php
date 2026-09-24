<?php

namespace App\Support\Airtable;

use RuntimeException;

/**
 * The record a PATCH named no longer exists in Airtable -- confirmed by a lookup, not guessed from
 * the status code, which Airtable shares with a plain permission failure.
 */
final class AirtableRecordGone extends RuntimeException {}
