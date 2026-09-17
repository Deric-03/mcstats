# MC Stats — statistiques des joueurs d'un serveur Minecraft

Site PHP à héberger sous Apache (Ubuntu) qui affiche les statistiques des joueurs d'un serveur
Minecraft Java, façon « tracker » :

- **Accueil** : statut du serveur en direct (MOTD, joueurs connectés, version, latence), totaux du serveur,
  top 5 dans plusieurs catégories, dernières connexions.
- **Classements** : 20 catégories (score, temps de jeu, succès, mobs tués, K/D, blocs minés, diamants,
  distance parcourue…) avec podium, rang et pagination.
- **Profil joueur** : skin, statut « En ligne depuis … », rang dans chaque catégorie, faits marquants, graphiques de progression,
  détails combat / minage / déplacements, les 126 succès avec leur date d'obtention,
  inventaire et coffre de l'Ender (avec enchantements et contenu des shulkers), vie, faim, XP, position.
- **Recherche** de joueur avec autocomplétion (pseudo ou UUID).

Les fichiers du serveur (`stats/`, `advancements/`, `data/`) sont lus **chaque minute** et stockés dans
MariaDB : les visiteurs ne touchent jamais aux fichiers du serveur. Seuls les joueurs dont les fichiers ont
changé sont relus.

### Versions de Minecraft compatibles

Serveurs Java vanilla, Spigot et Paper **1.16 et plus récents**. Les anciens formats de fichiers
(dossier `playerdata`, objets d'avant 1.20.5, effets d'avant 1.20.2, modèles d'avant 1.21.4) sont
reconnus automatiquement. Testé avec les clients 1.16.5, 1.18.2, 1.20.1, 1.21.1, 26.2 et 26.3.

| Fonction | Versions |
|----------|----------|
| Statistiques, classements, succès, inventaires | 1.16 et plus |
| Statut du serveur | 1.7 et plus |
| Carte | 1.19.4 et plus (versions disponibles de Pl3xMap) |

Avec Spigot ou Paper, les pseudos sont lus dans les fichiers joueurs. Avec un serveur vanilla, ils
viennent de `usercache.json` ou de l'API Mojang.

---

## 1. Prérequis (Ubuntu)

```bash
sudo apt update
sudo apt install apache2 mariadb-server php libapache2-mod-php php-mysql php-curl php-zip php-mbstring
```

- PHP 8.1 ou plus récent.
- `php-zip` sert à extraire les icônes et la liste des succès depuis le client Minecraft officiel
  (sans lui, le site fonctionne mais sans icônes).
- Le serveur (NAS) doit avoir accès à Internet pour la première installation des ressources
  (traductions, icônes) et pour les têtes/skins des joueurs (service `mc-heads.net`).

## 2. Récupérer le site

Le site est récupéré avec git, sous le compte qui fait tourner le serveur Minecraft (voir l'étape 5,
ci-dessous `minecraft`, à remplacer par le vôtre). Apache n'a besoin que de lire le code.

```bash
sudo install -d -o minecraft -g www-data -m 2775 /var/www/html/mcstats
sudo -u minecraft git clone https://git.nascedric.fr/Cedric/mcstats.git /var/www/html/mcstats
cd /var/www/html/mcstats
sudo install -d -o minecraft -g www-data -m 2775 data map
sudo -u minecraft cp config.sample.php config.php
sudo chmod 640 config.php
```

Le dépôt contient le code et les ressources Minecraft (traductions, succès, icônes). Restent hors de git :
`config.php` (mot de passe de la base), la carte (`map/`, écrite par Pl3xMap) et les fichiers de travail
de la synchronisation (`data/status.json`, `data/sync.lock`). Une mise à jour ne les touche jamais.

### Mettre à jour le site

```bash
cd /var/www/html/mcstats && sudo -u minecraft git pull --ff-only
```

Si `apache/mcstats.conf` a changé, recopiez-le puis rechargez Apache (étape 8).

## 3. Base de données MariaDB

```bash
sudo mysql
```

```sql
CREATE DATABASE mcstats CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mcstats'@'localhost' IDENTIFIED BY 'un-mot-de-passe-solide';
GRANT ALL PRIVILEGES ON mcstats.* TO 'mcstats'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Les tables sont créées automatiquement au premier lancement.

## 4. Configuration (`config.php`)

Les réglages essentiels :

| Clé | Rôle |
|-----|------|
| `players_path` | Dossier des données joueurs. Structure récente : `…/world/players` (contient `data/`, `stats/`, `advancements/`). Ancienne structure : le dossier du monde (`playerdata/`, `stats/`, `advancements/`). Détection automatique. |
| `usercache_path` | Optionnel : `usercache.json` du serveur (pseudos de secours). |
| `db` | Connexion MariaDB (`host`, `name`, `user`, `pass`). |
| `sync_interval` | Intervalle de synchronisation en secondes (60 = chaque minute). Doit correspondre à la fréquence du cron. |
| `server.host` / `server.port` | Adresse utilisée par le site pour interroger le serveur Minecraft (souvent `127.0.0.1` / `25565`). |
| `server.display_address` | Adresse affichée aux visiteurs avec un bouton « Copier » (laisser vide pour la masquer). |
| `hidden_players` | Pseudos ou UUID à masquer du site (ex. comptes admin). |
| `show_position` | `false` pour masquer coordonnées, point de réapparition et lieu de la dernière mort. |
| `show_inventory` | `false` pour masquer l'inventaire et le coffre de l'Ender. |
| `score_weights` | Pondérations du score général (voir plus bas). |

## 5. Qui lance la synchronisation ?

Le serveur Minecraft écrit les fichiers `.dat` (inventaire, position, vie) avec les droits `rw-------`.
Seul le compte qui fait tourner le serveur peut les lire, et ni les groupes ni les ACL n'y changent rien :
le masque ACL de chaque nouveau fichier est remis à `---`. La synchronisation doit donc tourner sous
**le compte du serveur Minecraft** (ci-dessous `minecraft`, à remplacer par le vôtre), et non sous
`www-data`. Apache n'a alors besoin d'aucun accès aux fichiers du serveur.

Dans `config.php`, désactivez la synchronisation déclenchée par les visites, qui tournerait sous `www-data` :

```php
'web_sync_fallback' => false,
```

Vérification (doit lister les fichiers joueurs) :

```bash
sudo -u minecraft ls /opt/minecraft/world/players/data
```

Si un `.dat` est tout de même illisible, le site garde les derniers inventaires connus et affiche
« ⚠ données partielles » en bas de page.

## 6. Première installation des ressources et synchronisation

```bash
sudo -u minecraft sh -c 'umask 002; php /var/www/html/mcstats/cron/sync.php'
```

Le dépôt contient déjà les ressources de la version 26.3 (traductions, succès, icônes). Pour que les succès
affichés correspondent exactement à une autre version de Minecraft, renseignez `mc_version` dans
`config.php` puis lancez la même commande avec `--assets`. Elle télécharge depuis les serveurs de Mojang
les traductions françaises, la liste des succès et les icônes (client Minecraft d'environ 40 Mo, supprimé
après extraction).

Les icônes ne sont jamais supprimées : le dossier `assets/mc/` cumule celles de toutes les versions
installées. Pour une même version, les fichiers produits sont toujours identiques.

## 7. Synchronisation automatique chaque minute (cron)

Dans la crontab du compte du serveur Minecraft :

```bash
sudo crontab -u minecraft -e
```

Ajouter la ligne (le `umask 002` garde les fichiers du cache modifiables par Apache) :

```
* * * * * umask 002; php /var/www/html/mcstats/cron/sync.php --quiet
```

`web_sync_fallback` (synchronisation déclenchée par une visite quand les données ont plus d'une minute
et demie) ne sert qu'en secours, là où aucun cron n'est possible : elle tourne sous `www-data` et ne peut
pas lire les fichiers `.dat`.

Une synchronisation sans changement prend quelques millisecondes : la fréquence d'une minute est sans
impact sur le NAS.

Options du script :

| Commande | Effet |
|----------|-------|
| `php cron/sync.php` | Synchronise les joueurs modifiés |
| `php cron/sync.php --force` | Recalcule tous les joueurs |
| `php cron/sync.php --assets` | Réinstalle traductions, icônes et succès |
| `php cron/sync.php --jar=/chemin/client.jar` | Installe les ressources depuis un client déjà téléchargé |
| `php cron/sync.php --quiet` | N'affiche que les erreurs |

### Fraîcheur des statistiques

Le serveur Minecraft n'écrit les fichiers de statistiques qu'à ses sauvegardes automatiques (toutes les
5 minutes par défaut) et à la déconnexion d'un joueur. La synchronisation chaque minute récupère chaque
sauvegarde au plus vite. Pour des statistiques réellement rafraîchies chaque minute, réduisez l'intervalle
de sauvegarde des joueurs. Avec Paper, dans `config/paper-global.yml` (le nom de l'option peut varier
selon la version de Paper) :

```yaml
player-auto-save:
  rate: 1200   # en ticks : 1200 = 1 minute (-1 = même intervalle que la sauvegarde du monde)
```

Cette sauvegarde ne concerne que les fichiers des joueurs connectés (quelques kilo-octets chacun).

L'indicateur « En ligne », lui, ne dépend pas de ces fichiers : il interroge directement le serveur.

## 8. Apache

Les protections des dossiers sensibles (`src/`, `data/`, `cron/`, `templates/`, `config.php`) sont dans
le fichier [`apache/mcstats.conf`](apache/mcstats.conf). Le projet n'utilise pas de fichiers `.htaccess`,
refusés par certains outils de synchronisation comme Nextcloud.

```bash
sudo cp /var/www/html/mcstats/apache/mcstats.conf /etc/apache2/conf-available/mcstats.conf
sudo a2enmod headers expires
sudo a2enconf mcstats
sudo systemctl reload apache2
```

Si le site est installé ailleurs que dans `/var/www/html/mcstats`, remplacez ce chemin dans le fichier
`mcstats.conf` avant de l'activer.

Le site est alors accessible sur `http://<ip-du-nas>/mcstats/`.

Vérifiez que `http://<ip-du-nas>/mcstats/data/lang_fr.php` et `…/config.php` renvoient bien une erreur 403.

## 9. Statut du serveur

Le statut utilise le protocole standard « Server List Ping » (celui de la liste des serveurs du jeu).
Dans `server.properties`, laissez `enable-status=true`. La liste des joueurs connectés est celle que le
serveur envoie (12 joueurs maximum en vanilla ; avec Paper, `hide-online-players` doit rester à `false`).

Joueurs connectés :

- à chaque synchronisation, le site enregistre qui est connecté et depuis quand : le profil affiche
  « En ligne depuis 25 min » et les listes affichent « En ligne » au lieu de la dernière connexion ;
- dans le navigateur, les pastilles vertes et le compteur sont rafraîchis toutes les 30 secondes ;
- au-delà de 12 joueurs connectés, la liste envoyée par le serveur est incomplète : un joueur absent de
  cette liste garde alors son dernier état connu jusqu'à ce que le nombre de connectés redescende.

## 10. Le score

Le classement général utilise un score calculé à partir des statistiques. Valeurs par défaut :

| Élément | Points |
|---------|--------|
| Heure de jeu | +20 |
| Succès obtenu | +30 |
| Mob tué | +0,25 |
| Joueur tué | +15 |
| Mort | −5 |
| Bloc miné | +0,02 |
| Minerai de diamant | +3 |
| Débris antique | +5 |
| Kilomètre parcouru | +2 |
| Animal élevé | +0,5 |
| Échange villageois | +1 |
| Poisson pêché | +1 |
| Raid gagné | +50 |

Modifiez `score_weights` dans `config.php`. Le recalcul se fait automatiquement à la synchronisation suivante.
Le détail est affiché aux visiteurs sur la page Classements (« Comment est calculé le score ? »).

## 11. Carte (Pl3xMap)

La page « Carte » affiche le vrai monde vu du dessus, avec la tête des joueurs, une recherche et le choix
de la dimension. Les images sont produites par le plugin Paper
[Pl3xMap](https://modrinth.com/plugin/pl3xmap) : il les écrit directement dans le dossier du site et son
serveur web intégré est désactivé. C'est Apache qui les sert, aucun port supplémentaire n'est ouvert.

1. Dossier des images, modifiable par le compte du serveur Minecraft :

   ```bash
   sudo mkdir -p /var/www/html/mcstats/map
   sudo chown minecraft:www-data /var/www/html/mcstats/map
   sudo chmod 2775 /var/www/html/mcstats/map
   ```

2. Plugin et configuration minimale, **avant** de redémarrer le serveur. Prenez sur Modrinth la version
   de Pl3xMap correspondant à votre version de Minecraft :

   ```bash
   cd /opt/minecraft/plugins
   wget https://cdn.modrinth.com/data/34T8oVNY/versions/2BipH1Kz/Pl3xMap-26.2-554.jar
   mkdir -p Pl3xMap
   printf 'settings:\n  web-directory:\n    path: /var/www/html/mcstats/map/\n  internal-webserver:\n    enabled: false\n' > Pl3xMap/config.yml
   ```

   Pl3xMap complète lui-même ce fichier avec ses valeurs par défaut au démarrage.

3. Redémarrez le serveur Minecraft, puis lancez le rendu complet dans sa console :
   `map fullrender <monde>` (la touche Tab complète le nom du monde). Le premier rendu peut être long sur
   un grand monde. Ensuite, Pl3xMap met à jour tout seul les zones modifiées.

4. Activez la carte dans `config.php` :

   ```php
   'map' => ['enabled' => true],
   ```

5. Rechargez la configuration Apache (étape 8) : elle demande aux navigateurs de revalider les images
   de la carte, qui changent en continu.

Sur la carte :

- les joueurs connectés apparaissent en direct (positions fournies par Pl3xMap) ;
- les joueurs hors ligne apparaissent à leur dernière position connue, sauf si `show_position` ou
  `map.show_offline_players` vaut `false` ;
- les joueurs de `hidden_players` n'apparaissent jamais sur la page du site. Pl3xMap publie aussi sa
  propre carte sur `/map/` : pour y masquer un joueur, tapez `map hide <joueur>` dans la console ;
- chaque profil propose un bouton « Voir sur la carte », et `carte.php?p=Pseudo` centre la carte sur un joueur.
- à l'ouverture, la carte se centre sur la zone où il y a le plus de joueurs (connectés en priorité, sinon leurs dernières positions), dans la bonne dimension. Mettre `'center_on_players' => false` dans la section `map` pour toujours ouvrir sur le point d'apparition.

## Dépannage

| Symptôme | Solution |
|----------|----------|
| « Dossier des joueurs introuvable » en bas de page | Vérifier `players_path` et que la synchronisation tourne sous le compte du serveur Minecraft (étape 5). Tester avec `sudo -u minecraft php cron/sync.php`. |
| Onglet « Inventaire & état » absent, niveau d'XP à 0, ou « ⚠ données partielles » en bas de page | La synchronisation tourne sous un compte qui ne peut pas lire les `.dat` (souvent `www-data`) : voir l'étape 5, puis lancer `php cron/sync.php --force` sous le bon compte. |
| « Base de données inaccessible » | Vérifier la section `db` de `config.php` et que `php-mysql` est installé. |
| Pas d'icônes | Installer `php-zip` puis lancer `sudo -u minecraft sh -c 'umask 002; php cron/sync.php --assets'`. |
| Pseudos affichés comme des UUID | Serveur non Paper/Spigot : renseigner `usercache_path`, ou laisser `mojang_lookup` activé (mode online). |
| Statut « hors ligne » alors que le serveur tourne | Vérifier `server.host` / `server.port` et `enable-status=true`. |
| Graphiques vides | Normal les premiers jours : l'historique se construit jour après jour. |
| Ancien skin affiché après un changement | Le skin est revérifié auprès de Mojang à la connexion du joueur, puis toutes les 10 min tant qu'il est en ligne (toutes les 6 h sinon). Il faut `mojang_lookup` à `true` (valeur par défaut). |
| « La carte n'est pas encore disponible » | Vérifier `'map' => ['enabled' => true]` dans `config.php`, et que Pl3xMap a bien créé `map/tiles/settings.json` (étape 11). |
| Carte grise ou trouée | Le rendu n'est pas terminé (`map status` dans la console), ou Apache ne peut pas lire les images : vérifier avec `ls -l /var/www/html/mcstats/map/tiles`. |

## Structure du projet

```
mcstats/
├── index.php            Accueil
├── leaderboard.php      Classements
├── players.php          Liste des joueurs
├── player.php           Profil d'un joueur / résultats de recherche
├── carte.php            Carte du monde (images Pl3xMap)
├── api/                 search.php (autocomplétion), status.php (statut serveur), map.php (positions)
├── apache/mcstats.conf  Configuration Apache (protections, cache)
├── cron/sync.php        Synchronisation en ligne de commande
├── src/                 Code PHP (lecture NBT, synchro, base de données, statut…)
├── templates/           En-tête et pied de page
├── assets/              CSS, JS, Chart.js, Leaflet, icônes Minecraft (assets/mc)
├── map/                 Images de la carte, écrites par Pl3xMap (créé à l'installation de la carte)
├── data/                Cache : traductions, liste des succès, statut (non public)
└── config.sample.php    Modèle de configuration
```

Site non officiel, non affilié à Mojang ou Microsoft.
