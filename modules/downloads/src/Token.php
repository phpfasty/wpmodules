<?php

/**
 * @deprecated 0.3.0 moved to PhpFasty\Module\Downloads\Domain\Token (WordPress-free download domain); this alias
 *             goes in 1.0. Signatures, validity and deny rules are unchanged.
 */

declare(strict_types=1);

namespace PhpFasty\Module\Downloads;

class_alias(Domain\Token::class, __NAMESPACE__.'\\Token');
