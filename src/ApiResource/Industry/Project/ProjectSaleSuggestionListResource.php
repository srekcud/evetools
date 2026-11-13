<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use App\ApiResource\Industry\WalletReadingResource;

class ProjectSaleSuggestionListResource
{
    public string $id;

    /** @var ProjectSaleSuggestionResource[] */
    public array $suggestions = [];

    /** @var WalletReadingResource[] */
    public array $walletReadings = [];
}
