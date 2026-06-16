<?php

declare(strict_types=1);

namespace App\Accounting\Exceptions;

use RuntimeException;

/**
 * De canonical → provider-mapping kon niet worden voltooid (bv. een doc-type dat
 * de adapter nog niet ondersteunt, of ontbrekende referentie-mapping voor de admin).
 */
class AccountingMappingException extends RuntimeException {}
