<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization\Exception;

/**
 * The query was rejected before it reached the database - it was not read-only, or
 * it tried to reach outside the anonymized schema.
 *
 * This is a second line of defence only. The real boundary is the database grant of
 * the read-only account; this catches mistakes earlier and with a clearer message.
 */
class QueryNotAllowedException extends \RuntimeException
{
}
