<?php

namespace App\Jobs;

/** Kept so already-serialized Redis jobs execute the common lifecycle. */
class SyncShopify extends SyncMarketplace {}
