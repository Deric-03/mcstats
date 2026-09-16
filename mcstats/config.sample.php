<?php
/**
 * Configuration de MC Stats.
 * Copier ce fichier en "config.php" puis adapter les valeurs.
 */
return [
    // Nom affiché dans l'en-tête du site
    'site_name' => 'MC Stats',
    'timezone'  => 'Europe/Paris',

    // ------------------------------------------------------------------
    // Données joueurs
    // ------------------------------------------------------------------
    // Dossier "players" du monde (structure récente : players/data, players/stats, players/advancements)
    // ou dossier du monde pour l'ancienne structure (world/playerdata, world/stats, world/advancements).
    // La structure est détectée automatiquement.
    'players_path' => '/opt/minecraft/world/players',

    // Optionnel : usercache.json du serveur (pseudos de secours si absents des .dat)
    'usercache_path' => '/opt/minecraft/usercache.json',

    // Interroger l'API Mojang pour les pseudos inconnus (serveur en mode "online" uniquement)
    'mojang_lookup' => true,

    // ------------------------------------------------------------------
    // Base de données
    // ------------------------------------------------------------------
    'db' => [
        'driver'  => 'mysql',          // 'mysql' (MySQL / MariaDB) ou 'sqlite'
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'mcstats',
        'user'    => 'mcstats',
        'pass'    => 'change-moi',
        // utilisé seulement si driver = sqlite
        'sqlite_path' => __DIR__ . '/data/mcstats.sqlite',
    ],

    // ------------------------------------------------------------------
    // Synchronisation
    // ------------------------------------------------------------------
    // Intervalle entre deux synchronisations (secondes) — doit correspondre à la fréquence du cron
    'sync_interval' => 60,
    // Secours sans cron : une visite déclenche la synchro quand les données sont trop vieilles.
    // Elle tourne sous l'utilisateur d'Apache, qui ne peut pas lire les .dat du serveur :
    // mettre false quand le cron tourne sous le compte du serveur Minecraft (voir README, étape 5).
    'web_sync_fallback' => true,

    // ------------------------------------------------------------------
    // Statut du serveur Minecraft
    // ------------------------------------------------------------------
    'server' => [
        'enabled'         => true,
        'host'            => '127.0.0.1',   // adresse utilisée par le site pour interroger le serveur
        'port'            => 25565,
        'display_address' => '',            // adresse affichée aux visiteurs, ex. "play.monserveur.fr" ('' = masquée)
        'timeout'         => 2,             // secondes
        'cache_seconds'   => 20,            // durée de cache du statut
    ],

    // ------------------------------------------------------------------
    // Carte (images générées par le plugin Pl3xMap, voir README)
    // ------------------------------------------------------------------
    'map' => [
        'enabled'    => false,                   // true une fois Pl3xMap installé
        'tiles_path' => __DIR__ . '/map/tiles',  // dossier "tiles" de Pl3xMap sur le disque
        'tiles_url'  => 'map/tiles',             // ce même dossier vu depuis le navigateur
        // Afficher la dernière position connue des joueurs hors ligne
        // (ignoré si show_position = false : seuls les joueurs connectés apparaissent)
        'show_offline_players' => true,
        'refresh_seconds' => 5,                  // rafraîchissement des positions dans le navigateur
    ],

    // ------------------------------------------------------------------
    // Affichage
    // ------------------------------------------------------------------
    // Version de Minecraft pour les traductions / icônes ('' = dernière version stable)
    'mc_version' => '',

    // Joueurs à masquer du site (UUID ou pseudo)
    'hidden_players' => [],

    // Afficher la position / le point de réapparition / le lieu de la dernière mort
    'show_position' => true,
    // Afficher l'inventaire et le coffre de l'Ender
    'show_inventory' => true,

    // Service de rendu des skins
    'skin_url' => 'https://mc-heads.net',

    // ------------------------------------------------------------------
    // Calcul du score (classement général)
    // ------------------------------------------------------------------
    'score_weights' => [
        'play_time_hour' => 20,    // par heure de jeu
        'advancement'    => 30,    // par succès obtenu
        'mob_kill'       => 0.25,  // par mob tué
        'player_kill'    => 15,    // par joueur tué
        'death'          => -5,    // par mort
        'block_mined'    => 0.02,  // par bloc miné
        'diamond'        => 3,     // par minerai de diamant miné
        'ancient_debris' => 5,     // par débris antique miné
        'distance_km'    => 2,     // par kilomètre parcouru
        'animal_bred'    => 0.5,   // par animal élevé
        'trade'          => 1,     // par échange avec un villageois
        'fish'           => 1,     // par poisson pêché
        'raid_won'       => 50,    // par raid gagné
    ],
];
