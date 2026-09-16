<?php

declare(strict_types=1);

namespace App\Import\Database;

use App\Import\Scope\CadastralScope;

interface TerritoryImporter
{
    public function importTerritory(
        int $datasetId,
        CadastralScope $scope,
        string $kuCode,
        string $downloadTarget,
    ): ImportTerritoryResult;
}
