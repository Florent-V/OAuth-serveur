# Caddy : un fichier par application

← [Documentation](README.md) · voir aussi : [Installation – production](installation-production.md)

Quand le Caddy de l'hôte sert plusieurs applications, le `Caddyfile` devient vite long. La directive `import`
(qui accepte les jokers `*`) permet de garder un `Caddyfile` principal court et un fichier par application.

## Structure

```
/etc/caddy/
├── Caddyfile            ← options globales + morceaux communs + import
└── sites/
    ├── oauth.caddy
    ├── app1.caddy
    └── kopeck.caddy
```

## `/etc/caddy/Caddyfile`

```caddy
# Options globales : toujours en premier, une seule fois
{
	email toi@mydomain.com
}

# Morceaux réutilisables (snippets)
(securite) {
	encode zstd gzip
	header {
		# Retirer includeSubDomains si un sous-domaine sert encore du HTTP
		Strict-Transport-Security "max-age=31536000; includeSubDomains"
		-Server
	}
}

# Journal par site, sans secrets. {args[0]} = nom du fichier
(journal) {
	log {
		output file /var/log/caddy/{args[0]}.access.log {
			roll_size 20mb
			roll_keep 10
		}
		format filter {
			request>uri regexp "(/reset-password/reset/)[^?]+|([?&](?:code|code_verifier|client_secret)=)[^&]*" "${1}${2}REDACTED"
			request>headers>Authorization delete
			request>headers>Cookie delete
		}
	}
}

import sites/*.caddy
```

## `/etc/caddy/sites/oauth.caddy`

```caddy
oauth.mydomain.com {
	import securite
	import journal oauth

	# Conteneur FrankenPHP ; X-Forwarded-* transmis (TRUSTED_PROXIES=private_ranges côté Symfony)
	reverse_proxy 127.0.0.1:8080 {
		health_uri /login
		health_interval 30s
	}
}
```

## Autres applications

Application en conteneur Docker (port publié sur `127.0.0.1` uniquement) — `/etc/caddy/sites/app1.caddy` :

```caddy
app1.mydomain.com {
	import securite
	import journal app1
	reverse_proxy 127.0.0.1:8081
}
```

Application Symfony native (PHP-FPM) — `/etc/caddy/sites/app2.caddy` :

```caddy
app2.mydomain.com {
	import securite
	import journal app2
	root * /var/www/app2/public
	php_fastcgi unix//run/php/php8.4-fpm.sock
	file_server
}
```

## Mise en place sans casser l'existant

```bash
sudo cp /etc/caddy/Caddyfile /etc/caddy/Caddyfile.bak-$(date +%F)   # sauvegarde
sudo mkdir -p /etc/caddy/sites
# Déplacer chaque bloc « domaine { … } » de l'ancien Caddyfile dans son fichier sites/xxx.caddy
sudo caddy fmt --overwrite /etc/caddy/Caddyfile                       # indentation propre
sudo -u caddy caddy validate --config /etc/caddy/Caddyfile            # doit finir par « Valid configuration »
sudo systemctl reload caddy
systemctl status caddy --no-pager
```

Le `reload` est sans risque : si la nouvelle configuration est invalide, Caddy la refuse et garde l'ancienne en
service. Ouvrir ensuite chaque site pour vérifier.

En cas de problème : `sudo cp /etc/caddy/Caddyfile.bak-AAAA-MM-JJ /etc/caddy/Caddyfile && sudo systemctl reload caddy`.

## Bonnes pratiques

- Valider **en tant qu'utilisateur `caddy`** (`sudo -u caddy caddy validate …`), jamais en root : la validation
  ouvre les fichiers de journaux ; en root, elle les crée avec root comme propriétaire, et le `reload` suivant échoue
  (`open /var/log/caddy/….access.log: permission denied`). Correctif : `sudo chown caddy:caddy /var/log/caddy/*.log`.
- Les options globales (`{ … }`) ne vont **que dans le `Caddyfile` principal**, en tout début de fichier.
- Le motif `*.caddy` n'importe que ces fichiers : pour désactiver un site sans le supprimer, le renommer
  (ex. `app1.caddy.off`), puis `sudo systemctl reload caddy`.
- `import securite` n'est pas obligatoire partout : `includeSubDomains` (HSTS) impose HTTPS à **tous** les
  sous-domaines, à n'utiliser que s'ils sont tous en HTTPS.
- Le filtre des journaux masque des paramètres propres au serveur OAuth (codes, `client_secret`, jeton de
  réinitialisation) ; il ne gêne pas les autres sites.
- Les applications en conteneur ne doivent publier leur port que sur `127.0.0.1` (`"127.0.0.1:8081:80"` dans
  `compose.yaml`) : Docker contourne ufw.
- Les fichiers de `/etc/caddy` appartiennent à `root` et sont lisibles par tous (`644`) : Caddy doit pouvoir les lire,
  et ils ne contiennent pas de secret.
