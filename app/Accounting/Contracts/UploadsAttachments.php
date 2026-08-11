<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

/**
 * Capability `documents.attachments` — een declaratie, geen gedrag.
 *
 * De upload gebeurt binnen `push()`; dit merk bestaat zodat
 * `GET /v1/accounting/capabilities` kan zeggen of bijlagen meesturen zin heeft.
 * Bewust zonder methode: er is geen aanroeper die een bijlage los van een boeking
 * uploadt, en een methode zonder aanroeper is schuld.
 */
interface UploadsAttachments {}
