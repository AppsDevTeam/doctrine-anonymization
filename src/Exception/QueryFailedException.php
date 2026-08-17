<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization\Exception;

/**
 * The query was allowed but the database refused or failed to run it (syntax error,
 * unknown column, timeout, missing table, ...).
 */
class QueryFailedException extends \RuntimeException
{
}
