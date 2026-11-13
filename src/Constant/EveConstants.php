<?php

declare(strict_types=1);

namespace App\Constant;

final class EveConstants
{
    public const JITA_SOLAR_SYSTEM_ID = 30000142;
    public const JITA_STATION_ID = 60003760;
    public const THE_FORGE_REGION_ID = 10000002;
    public const JITA_NPC_CORPORATION_ID = 1000035;
    public const JITA_NPC_FACTION_ID = 500001;
    public const DEFAULT_EXPORT_COST_PER_M3 = 1200.0;
    public const STRUCTURE_MARKET_SCOPE = 'esi-markets.structure_markets.v1';
    public const CHARACTER_BLUEPRINTS_SCOPE = 'esi-characters.read_blueprints.v1';
    public const CORPORATION_BLUEPRINTS_SCOPE = 'esi-corporations.read_blueprints.v1';
    public const CORPORATION_ASSETS_SCOPE = 'esi-assets.read_corporation_assets.v1';
    public const CORPORATION_ROLES_SCOPE = 'esi-characters.read_corporation_roles.v1';
    public const OFFICE_LOCATION_FLAG = 'OfficeFolder';
    public const ASSET_SAFETY_LOCATION_FLAG = 'AssetSafety';
    public const CHARACTER_ORDERS_SCOPE = 'esi-markets.read_character_orders.v1';
    public const CELESTIAL_CATEGORY_ID = 2;
    public const SHIP_CATEGORY_ID = 6;
    public const DEPLOYABLE_CATEGORY_ID = 22;
    public const STARBASE_CATEGORY_ID = 23;
    public const STRUCTURE_CATEGORY_ID = 65;
    public const CARGO_CONTAINER_GROUP_ID = 12;
    public const SECURE_CARGO_CONTAINER_GROUP_ID = 340;
    public const AUDIT_LOG_SECURE_CONTAINER_GROUP_ID = 448;
    public const FREIGHT_CONTAINER_GROUP_ID = 649;
}
