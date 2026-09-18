# MC Stats — statistiques des joueurs d'un serveur Minecraft

Site PHP à héberger sous Apache (Ubuntu) qui affiche les statistiques des joueurs d'un serveur
Minecraft Java, façon « tracker » :

- **Accueil** : statut du serveur en direct (MOTD, joueurs connectés, version, latence), totaux du serveur,
  top 5 dans plusieurs catégories, dernières connexions.
- **Classements** : 20 catégories (score, temps de jeu, succès, mobs tués, K/D, blocs minés, diamants,
  distance parcourue…) avec podium, rang et pagination.
- **Profil joueur** : skin, statut « En ligne depuis … », rang dans chaque catégorie, faits marquants, graphiques de progression,
  détails combat / minage / déplacements, les 126 succès avec leur date d'obtention,
  inventaire et coffre de l'Ender (avec enchantements ; un clic sur un shulker ou un sac ouvre son
  contenu), vie, faim, XP, position.
- **Recherche** de joueur avec autocomplétion (pseudo ou UUID).
- **Comptes joueurs** (optionnels) : demandes de whitelist, espace admin, badge « Vous » sur son propre
  profil, et contenus privés (coffre de l'Ender, sac à dos, homes) réservés au joueur et aux admins.

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

### Plugins compatibles

Aucun plugin n'est nécessaire : ceux-ci ajoutent des fonctions au site s'ils sont installés.

| Plugin | Ce qu'il apporte au site | Réglage |
|--------|--------------------------|---------|
| [Pl3xMap](https://modrinth.com/plugin/pl3xmap) | Page « Carte » : monde vu du dessus, positions des joueurs en direct | section `map` (étape 11) |
| [Geyser](https://geysermc.org) + [Floodgate](https://geysermc.org/wiki/floodgate/) | Demandes de whitelist des joueurs Bedrock (commande `fwhitelist`) | section `accounts` (étape 12) |
| [Minepacks](https://www.spigotmc.org/resources/minepacks.19286/) | Contenu du sac à dos sur le profil. Stockage SQLite uniquement (`backpack.db`, réglage par défaut du plugin) ; testé avec Minepacks 2.5.9 | `plugins.minepacks_db` (étape 13) |
| [UltimateHomes](https://www.spigotmc.org/resources/64210/) (`playerdata/<uuid>.yml`) ou [EssentialsX](https://essentialsx.net) (`userdata/<uuid>.yml`) | Liste des homes sur le profil, avec un lien vers la carte | `plugins.homes_path` (étape 13) |

Le sac à dos et les homes demandent les comptes (étape 12) : ils ne sont visibles que par le joueur
lui-même et par les admins.

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
| `show_inventory` | `false` pour masquer l'inventaire, le coffre de l'Ender et le sac à dos. |
| `plugins` | Fichier `backpack.db` de Minepacks et dossier des homes (étape 13). |
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

La page « Carte » affiche le vrai monde vu du dessus, avec la tête des joueurs, une recherche, le choix
de la dimension et, pour les joueurs connectés, leurs homes (étape 13). Les images sont produites par le plugin Paper
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

5. Rechargez la configuration Apache (étape 8). Elle ferme l'accès direct au dossier `map/` : les images de
   la carte sont servies par le site (`api/tile.php`), ce qui permet de les réserver aux joueurs connectés
   (étape 12). La carte d'origine de Pl3xMap sur `/map/` n'est donc plus accessible.

Sur la carte :

- les joueurs connectés apparaissent en direct (positions fournies par Pl3xMap) ;
- les joueurs hors ligne apparaissent à leur dernière position connue, sauf si `show_position` ou
  `map.show_offline_players` vaut `false` ;
- les joueurs de `hidden_players` n'apparaissent jamais sur la carte ;
- chaque profil propose un bouton « Voir sur la carte », et `carte.php?p=Pseudo` centre la carte sur un joueur ;
- à l'ouverture, la carte se centre sur la zone où il y a le plus de joueurs (connectés en priorité, sinon leurs dernières positions), dans la bonne dimension. Mettre `'center_on_players' => false` dans la section `map` pour toujours ouvrir sur le point d'apparition.

## 12. Comptes et demandes de whitelist

Les joueurs demandent la whitelist depuis le site (bouton « Rejoindre ») : la demande crée leur compte, et
la validation par un admin active le compte **et** ajoute le joueur à la whitelist du serveur. La carte, les
positions et les inventaires sont alors réservés aux joueurs connectés ; le reste du site reste public.

- **Java** : le pseudo est vérifié auprès de Mojang (un pseudo inexistant est refusé) et la casse est corrigée.
- **Bedrock** (Geyser / Floodgate) : le gamertag est vérifié auprès de l'API de GeyserMC, qui ne connaît que
  les joueurs déjà passés sur un serveur Geyser. Un gamertag inconnu est accepté mais marqué
  « Gamertag non vérifié » dans l'espace admin. Le compte prend le préfixe Floodgate (`bedrock_prefix`).

### Activer

1. Dans `config.php` :

   ```php
   'accounts' => ['enabled' => true],
   ```

2. RCON, pour que la validation ajoute le joueur à la whitelist. Dans `server.properties` du serveur
   Minecraft (mot de passe long et aléatoire), puis redémarrer le serveur :

   ```properties
   enable-rcon=true
   rcon.port=25575
   rcon.password=un-long-mot-de-passe-aleatoire
   ```

   **N'ouvrez jamais le port 25575 sur votre box** : seul le site, sur la même machine, doit y accéder.
   Puis dans `config.php`, section `server` :

   ```php
   'rcon_password' => 'un-long-mot-de-passe-aleatoire',
   ```

   Sans RCON, la validation active seulement le compte et l'espace admin indique la commande à taper dans la
   console. Les commandes envoyées sont réglables (`whitelist_java`, `whitelist_bedrock`).

3. Premier admin : faites votre propre demande sur le site, puis activez-la en admin sur le serveur
   (le premier admin ne peut pas être créé depuis le site ; il pourra ensuite nommer les suivants) :

   ```bash
   php /var/www/html/mcstats/cron/admin.php VotrePseudo
   ```

   `php cron/admin.php --liste` affiche les admins, `php cron/admin.php Pseudo --retirer` retire les droits.

   Ce premier admin devient l'**admin principal** (voir ci-dessous).

### Espace admin

Le lien « Admin » de l'en-tête affiche le nombre de demandes et de signalements à traiter. Pour chaque demande : édition,
message du joueur, indication « Déjà whitelisté » et « A déjà joué sur le serveur », boutons Valider /
Refuser.

Chaque compte de la liste a un menu **« Actions »** :

| Action | Effet |
|--------|-------|
| Nouveau mot de passe | Mot de passe provisoire à transmettre au joueur, qui devra le changer à sa prochaine connexion. |
| Désactiver / Réactiver le compte | Coupe ou rend l'accès au site, et retire le joueur de la whitelist du serveur ou l'y remet. |
| Rendre admin / Retirer les droits admin | Droits sur le site uniquement : le joueur ne devient pas opérateur du serveur Minecraft. |
| Expulser du serveur | Déconnecte le joueur s'il est en ligne (RCON). |
| Bannir du serveur / Lever le bannissement | Bannit sur le serveur (RCON) et désactive le compte du site. |
| Donner / Retirer le terminal | Ouvre ou ferme l'accès au terminal du serveur. Réservé à l'admin principal. |
| Supprimer le compte | Efface le compte du site et retire le joueur de la whitelist du serveur. |

Tout admin peut nommer ou retirer d'autres admins, mais pas se retirer ses propres droits, pour qu'il reste
toujours au moins un admin. De même, on ne peut ni s'expulser, ni se bannir, ni se supprimer soi-même.

### Admin principal

Un compte est l'**admin principal** : les autres admins n'ont aucun droit sur lui. Concrètement, son compte
porte l'étiquette « Admin principal », son menu Actions est remplacé par « Protégé », et pour les autres
admins :

- aucune action possible sur lui (mot de passe, désactivation, droits admin, expulsion, bannissement,
  suppression…), même en envoyant la requête à la main ;
- son journal est refusé, et ce qui le concerne est retiré du « Journal du site » ;
- son sac à dos, ses homes et son coffre de l'Ender restent invisibles sur son profil.

Ce qu'il a fait sur les autres comptes reste visible dans leur journal à eux : sans cela, l'historique de
ces comptes serait trompeur.

Par défaut, c'est le premier admin (le plus ancien). Pour désigner quelqu'un d'autre, depuis le serveur —
personne ne peut le faire depuis le site :

```bash
php /var/www/html/mcstats/cron/admin.php Pseudo --principal
```

`php cron/admin.php --liste` indique lequel des admins est le principal.

### Terminal du serveur

Le bouton **« Terminal »**, en haut de l'espace admin, ouvre une console qui envoie des commandes au serveur
Minecraft par RCON et affiche sa réponse, comme la console du serveur.

- **Seul l'admin principal y a accès** au départ. Il peut l'ouvrir à un autre admin depuis le menu Actions
  de son compte (« Donner le terminal » / « Retirer le terminal ») ; ce compte porte alors l'étiquette
  « Terminal ». Personne d'autre ne peut donner ce droit.
- **Tout est enregistré au journal** : la commande et la réponse, avec l'auteur. L'admin principal voit les
  commandes de tout le monde, les autres seulement les leurs.
- Les commandes qui **visent l'admin principal** (son pseudo) sont refusées pour les autres admins, et la
  tentative est notée au journal.
- La barre oblique est facultative (`/list` ou `list`), 60 commandes par minute au maximum.

Ce droit donne le contrôle du serveur Minecraft (bannir, opérer, arrêter…) : ne l'accordez qu'à des
personnes de confiance. Il demande RCON (voir plus haut).

### Expulser et bannir

Ces deux actions demandent RCON (voir plus haut). Avant l'envoi, le site demande un **motif**, facultatif,
qui est montré au joueur par le serveur ; annuler la fenêtre annule l'action. La réponse du serveur est
affichée telle quelle (« Kicked … », « No player was found », « already banned »…).

Un bannissement désactive aussi le compte du site, ferme ses sessions et retire le joueur de la whitelist ;
une étiquette « Banni » apparaît dans la liste tant que le joueur est dans la liste des bannis du serveur.
« Lever le bannissement » ne réactive pas le compte du site : utilisez « Réactiver le compte », qui le remet
aussi dans la whitelist.

### La whitelist suit le compte

Désactiver, bannir ou supprimer un compte retire le joueur de la whitelist du serveur ; le réactiver l'y
remet. Pour Bedrock, la commande Floodgate reçoit le gamertag sans préfixe. Le résultat (réponse du serveur)
est affiché et gardé au journal. Si le serveur est éteint ou que RCON ne répond pas, l'action a quand même
lieu sur le site, le message passe en orange et donne la commande à taper à la main.

La révocation d'un compte usurpé ne touche pas la whitelist : le pseudo appartient au vrai joueur, qui va
refaire sa demande.

Commandes réglables dans `config.php`, section `accounts` :

```php
'unwhitelist_java'    => 'whitelist remove {name}',
'unwhitelist_bedrock' => 'fwhitelist remove {gamertag}',
```

Les commandes envoyées sont réglables dans `config.php`, section `accounts` (`{name}` = pseudo du joueur,
préfixe Bedrock compris, `{reason}` = motif saisi) :

```php
'kick_command'  => 'kick {name} {reason}',
'ban_command'   => 'ban {name} {reason}',
'unban_command' => 'pardon {name}',
```

### Signalement d'usurpation

Le site ne peut pas prouver qu'un joueur possède vraiment le compte Minecraft qu'il indique. Si quelqu'un
demande un pseudo qui a déjà un compte (actif ou en attente), le site lui propose « Ce n'est pas toi ? » :
il peut signaler une usurpation en indiquant son pseudo ou son identifiant Discord (obligatoire) et une
explication.

Le compte signalé **reste utilisable** jusqu'à la décision d'un admin. Dans l'espace admin, il remonte en
tête de la liste avec l'étiquette « Révocation demandée », le Discord et le message ; le compteur de
l'en-tête inclut les signalements. Après avoir contacté le joueur sur Discord :

- **Révoquer** : supprime le compte et ferme ses sessions ; le vrai joueur peut alors refaire sa demande.
  La whitelist du serveur n'est pas modifiée (retirez le joueur à la main si besoin).
- **Ignorer le signalement** : le compte reste tel quel.

Refuser une demande signalée clôt aussi le signalement. 3 signalements au maximum par adresse IP par heure.

### Sécurité

- Mots de passe chiffrés (bcrypt), jamais stockés en clair ; 8 caractères minimum.
- Session de 30 jours dans un cookie HttpOnly (Secure en HTTPS) ; seule son empreinte est en base.
  Changer de mot de passe ferme les autres sessions.
- Formulaires protégés contre les requêtes intersites (jeton CSRF).
- 5 échecs de connexion par pseudo en 15 minutes, puis blocage temporaire ; 5 demandes de whitelist par
  adresse IP par heure.

## 13. Contenus privés : sac à dos et homes (plugins)

Sur le profil d'un joueur :

- onglet **« Inventaire & état »** : le contenu de son sac à dos
  ([Minepacks](https://www.spigotmc.org/resources/minepacks.19286/)), sous l'inventaire et le coffre de l'Ender ;
- onglet **« Déplacements »** : la liste de ses homes
  ([UltimateHomes](https://www.spigotmc.org/resources/64210/) ou [EssentialsX](https://essentialsx.net)) —
  nom, dimension et coordonnées, avec un lien vers la carte quand Pl3xMap est installé.

**Sur la carte**, les homes apparaissent en plus, marqués d'un lit :

- un joueur connecté voit les siens dès l'ouverture de la carte ; la pastille « Mes homes », sous le choix
  de la dimension, les masque ou les remontre ;
- un admin a en plus la pastille « Tous les homes », et cliquer un joueur dans la liste (ou le chercher)
  affiche les homes de ce joueur, avec une pastille « Homes de … » ;
- les icônes se changent dans `config.php`, section `map` : `spawn_icon` pour le point d'apparition
  (objet Minecraft, par défaut la boussole) et `home_icon` pour les homes (vide = le lit dessiné pour le site).

Le réglage `map.extra_zoom_out` (3 par défaut, 0 à 6) ajoute des niveaux de dézoom au-delà de ceux générés
par Pl3xMap : les images du dernier niveau sont réduites par le navigateur, ce qui permet de voir le monde
entier d'un coup. Les images ne sont pas regénérées et les autres niveaux ne changent pas ; pour plus de
détail à ces distances, il faut augmenter le nombre de niveaux de dézoom dans la configuration de Pl3xMap
(réglage `zoom.max-out` du monde) puis relancer un rendu complet.

**Qui peut les voir ?** Quand les comptes sont activés (étape 12), le coffre de l'Ender, le sac à dos et les
homes sont **privés** : seuls le joueur, sur son propre profil (marqué du badge « Vous »), et les admins, sur
tous les profils, les voient. Une étiquette « Privé » le rappelle sur chaque carte. Les autres joueurs
connectés voient le reste du profil, inventaire compris. Sans comptes, le site est public comme avant et
seuls le sac à dos et les homes restent masqués.

1. Dans `config.php` (chemins à adapter ; laisser `''` pour désactiver l'un ou l'autre) :

   ```php
   'plugins' => [
       'minepacks_db' => '/opt/minecraft/plugins/Minepacks/backpack.db',
       'homes_path'   => '/opt/minecraft/plugins/UltimateHomes/playerdata',
   ],
   ```

   Pour EssentialsX, le dossier des homes est `plugins/Essentials/userdata`. Tout plugin qui écrit un
   fichier `<uuid>.yml` par joueur avec une section `homes` (`x`, `y`, `z`, `world`) est lu de la même façon.

2. Minepacks est lu avec l'extension SQLite de PHP :

   ```bash
   sudo apt install php-sqlite3
   ```

Les fichiers sont lus par la synchronisation, sous le compte du serveur Minecraft (étape 5) : Apache n'y
accède jamais. Ils ne sont relus que lorsqu'ils changent, puis copiés dans la base du site. Le sac à dos
affiché est celui que Minepacks a enregistré en dernier.

L'espace admin indique en haut de page le nombre de joueurs lus pour chaque plugin, ou l'erreur rencontrée
(fichier introuvable, extension manquante…). En cas d'erreur, les dernières données lues restent affichées.

Seul le stockage SQLite de Minepacks est pris en charge (pas MySQL). Les sacs enregistrés dans un format
trop ancien sont ignorés et comptés dans le journal de la synchronisation.

## 14. Journal par joueur (admins)

Chaque compte a un **journal**, atteint par « Voir le journal » dans son menu Actions ; « Journal du site »,
en haut de l'espace admin, montre la même chose pour tout le monde. Il est réservé aux admins.

Trois parties :

1. **Actions du site** : demande de whitelist, signalement, validation, refus, mot de passe réinitialisé,
   désactivation, droits admin, expulsion, bannissement, suppression… avec la date, l'auteur (l'admin, ou
   « le joueur » quand c'est lui qui a agi) et le motif saisi.
2. **Sur le serveur** : morts, connexions, déconnexions, chat, commandes et succès, lus dans le journal du
   serveur Minecraft (voir ci-dessous). Un champ filtre la liste.
   Chaque mort est détaillée : **cause** (chute, lave, noyade, explosion, foudre, gel, faim…), **auteur**
   (le mob avec son icône, ou le joueur avec sa tête et un lien vers son profil) et **lieu** — coordonnées,
   dimension et lien vers la carte. Le message brut du serveur est gardé en dessous.
   Le lieu vient du fichier du joueur (`LastDeathLocation`) : il apparaît à la synchronisation qui suit la
   mort, et manque si le fichier n'a pas encore été écrit par le serveur.
3. **Périodes de connexion** : début, fin et durée de chaque passage sur le serveur, plus le total des
   7 derniers jours. Elles sont construites par la synchronisation, sans lire les logs.

Les deux premières listes sont **paginées** : 150 lignes par page par défaut, au choix 50, 150, 500 ou 1000
(barre au-dessus de chaque liste), avec Précédent / Suivant en dessous. Le nombre total de lignes est
toujours affiché, et les listes ne chargent jamais plus d'une page à la fois, même avec des centaines de
milliers d'événements.

Le champ de filtre de « Sur le serveur » ne filtre que la page affichée.

### Lire le journal du serveur

À chaque synchronisation, le site lit les **nouvelles lignes** de `logs/latest.log` (un curseur est gardé en
base, le fichier est relu depuis le début après la rotation de minuit) et en tire les événements
reconnaissables. Les lignes techniques (connexion avec l'adresse IP, messages des plugins…) sont ignorées,
et rien n'est relu deux fois.

```php
'server_log' => [
    'path'      => '/opt/minecraft/logs/latest.log',
    'keep_days' => 90,    // les événements plus vieux sont effacés
    'chat'      => true,  // false : ne pas enregistrer les messages du chat
],
```

- Le journal démarre à la mise en place : il ne remonte pas dans le passé.
- Volume : les événements du serveur sont effacés après `keep_days` (90 jours par défaut) et les actions du
  site après `accounts.journal_keep_days` (2 ans par défaut, `0` pour tout garder). Les tables sont indexées
  sur le joueur, la date et le type.
- Au maximum 4 Mo de journal sont lus par passage ; s'il en reste, la suite est lue à la minute suivante.
- Le fichier est lu par la synchronisation, donc sous le compte du serveur Minecraft (étape 5).
- `'chat' => false` si vous préférez ne pas conserver les conversations des joueurs.
- Les messages de mort sont gardés tels que le serveur les écrit (« … was slain by Zombie »), donc en
  anglais si le serveur est en anglais.

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
| « Connecte-toi pour voir la carte » alors qu'on veut une carte publique | Les comptes sont activés : la carte est réservée aux joueurs connectés. Mettre `'accounts' => ['enabled' => false]` pour tout rendre public. |
| La validation d'une demande échoue (« connexion RCON impossible ») | Le serveur Minecraft doit être allumé, avec `enable-rcon=true` et le même mot de passe que `server.rcon_password`. |
| Journal « Sur le serveur » vide | Vérifier `server_log.path` dans `config.php`, puis le message d'erreur en haut de l'espace admin. Le journal ne se remplit qu'à partir de sa mise en place. |
| Pas de sac à dos ni de homes sur un profil | Ils n'apparaissent qu'au joueur sur son propre profil et aux admins. Vérifier la section `plugins` de `config.php`, puis l'état des plugins en haut de l'espace admin. |
| « l'extension PHP SQLite est absente » dans l'espace admin | `sudo apt install php-sqlite3` ; la synchronisation suivante lira Minepacks. |
| Carte grise ou trouée | Le rendu n'est pas terminé (`map status` dans la console), ou Apache ne peut pas lire les images : vérifier avec `ls -l /var/www/html/mcstats/map/tiles`. |

## Structure du projet

```
mcstats/
├── index.php            Accueil
├── leaderboard.php      Classements
├── players.php          Liste des joueurs
├── player.php           Profil d'un joueur / résultats de recherche
├── carte.php            Carte du monde (images Pl3xMap)
├── demande.php          Demande de whitelist (création du compte)
├── connexion.php        Connexion · compte.php : mon compte · admin.php : espace admin
├── api/                 search.php (autocomplétion), status.php (statut serveur), map.php (positions), tile.php (images de la carte)
├── apache/mcstats.conf  Configuration Apache (protections, cache)
├── cron/                sync.php (synchronisation), admin.php (gestion des admins)
├── src/                 Code PHP (lecture NBT, synchro, base de données, statut…)
├── templates/           En-tête et pied de page
├── assets/              CSS, JS, Chart.js, Leaflet, icônes Minecraft (assets/mc)
├── map/                 Images de la carte, écrites par Pl3xMap (créé à l'installation de la carte)
├── data/                Cache : traductions, liste des succès, statut (non public)
└── config.sample.php    Modèle de configuration
```

Site non officiel, non affilié à Mojang ou Microsoft.
