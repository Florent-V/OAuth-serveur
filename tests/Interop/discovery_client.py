#!/usr/bin/env python3
"""Test d'interopérabilité de la découverte : un client qui n'utilise qu'Authlib et PyJWT.

Configuré uniquement avec issuer, client_id et client_secret, il :
  1. lit /.well-known/oauth-authorization-server et vérifie que l'issuer correspond (RFC 8414) ;
  2. réussit un flux authorization code avec PKCE S256 (connexion web simulée, code MFA lu dans Mailpit si demandé) ;
  3. vérifie le jeton d'accès, puis un logout token reçu en back-channel, avec la clé du jwks_uri trouvée par son kid.

Usage :
  pip install authlib pyjwt cryptography requests beautifulsoup4
  python tests/Interop/discovery_client.py --issuer http://localhost:8080 --client-id app1 --client-secret ... \\
      --redirect-uri http://localhost:9000/callback --email alice@example.com --password ... \\
      --listen-port 9000 --mailpit http://localhost:8025 \\
      --revoke-command "docker compose exec -T php php bin/console app:user:revoke alice@example.com --logout-only"

L'application doit avoir pour back-channel logout URI http://<hôte joignable depuis le serveur>:<listen-port>/backchannel-logout.
"""

import argparse
import json
import re
import subprocess
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse

import jwt
import requests
from authlib.integrations.requests_client import OAuth2Session
from bs4 import BeautifulSoup


def fail(message):
    print(f"ECHEC : {message}")
    sys.exit(1)


def discover(issuer):
    url = f"{issuer}/.well-known/oauth-authorization-server"
    response = requests.get(url, timeout=10)
    response.raise_for_status()
    metadata = response.json()
    if metadata["issuer"] != issuer:
        fail(f"issuer {metadata['issuer']!r} différent de {issuer!r}")
    if "S256" not in metadata.get("code_challenge_methods_supported", []):
        fail("PKCE S256 non annoncé")
    return metadata


def csrf(html, name):
    # CSRF sans état : le jeton est fixe, le serveur contrôle l'en-tête Origin des POST
    return BeautifulSoup(html, "html.parser").find("input", {"name": name})["value"]


def web_login(session, authorization_url, args):
    """Suit /authorize jusqu'au retour vers redirect_uri (sans le suivre) et renvoie cette URL."""
    response = session.get(authorization_url, allow_redirects=False)
    # Redirections successives : /authorize -> /login (connexion) -> /authorize -> redirect_uri
    for _ in range(10):
        if args.verbose:
            print(response.status_code, response.url, response.headers.get("Location"))
        location = response.headers.get("Location", "")
        if response.status_code in (301, 302, 303) and location.startswith(args.redirect_uri):
            return location
        if response.status_code in (301, 302, 303):
            response = session.get(requests.compat.urljoin(response.url, location), allow_redirects=False)
        elif response.url.endswith("/login"):
            response = session.post(response.url, data={
                "_username": args.email, "_password": args.password, "_csrf_token": csrf(response.text, "_csrf_token"),
            }, headers={"Origin": args.issuer}, allow_redirects=False)
        elif "/2fa" in response.url:
            code = latest_mfa_code(args.mailpit)
            soup = BeautifulSoup(response.text, "html.parser")
            form = soup.find("form")
            response = session.post(requests.compat.urljoin(response.url, form["action"]), data={
                "_auth_code": code, "_csrf_token": csrf(response.text, "_csrf_token"),
            }, headers={"Origin": args.issuer}, allow_redirects=False)
        else:
            fail(f"réponse inattendue {response.status_code} sur {response.url}")
    fail("trop de redirections")


def latest_mfa_code(mailpit):
    if not mailpit:
        fail("code MFA demandé : fournir --mailpit")
    time.sleep(1)
    messages = requests.get(f"{mailpit}/api/v1/messages", timeout=10).json()["messages"]
    body = requests.get(f"{mailpit}/api/v1/message/{messages[0]['ID']}", timeout=10).json()["Text"]
    return re.search(r"\b(\d{6})\b", body).group(1)


class BackchannelReceiver:
    """Petit serveur qui reçoit les logout tokens envoyés par le serveur OAuth2."""

    def __init__(self, port):
        self.tokens = []
        tokens = self.tokens

        class Handler(BaseHTTPRequestHandler):
            def do_POST(self):
                body = self.rfile.read(int(self.headers["Content-Length"])).decode()
                tokens.append(parse_qs(body)["logout_token"][0])
                self.send_response(200)
                self.end_headers()

            def log_message(self, *args):
                pass

        self.server = HTTPServer(("0.0.0.0", port), Handler)
        threading.Thread(target=self.server.serve_forever, daemon=True).start()


def main():
    parser = argparse.ArgumentParser()
    for name in ("issuer", "client-id", "client-secret", "redirect-uri", "email", "password", "revoke-command"):
        parser.add_argument(f"--{name}", required=True)
    parser.add_argument("--listen-port", type=int, required=True)
    parser.add_argument("--mailpit")
    parser.add_argument("--verbose", action="store_true")
    args = parser.parse_args()
    args.issuer = args.issuer.rstrip("/")

    metadata = discover(args.issuer)
    receiver = BackchannelReceiver(args.listen_port)

    # Authlib : PKCE S256, adresses issues de la découverte uniquement
    oauth = OAuth2Session(args.client_id, args.client_secret, scope="profile email", redirect_uri=args.redirect_uri,
                          code_challenge_method="S256")
    verifier = "v" * 64
    url, state = oauth.create_authorization_url(metadata["authorization_endpoint"], code_verifier=verifier)
    callback = web_login(requests.Session(), url, args)
    token = oauth.fetch_token(metadata["token_endpoint"], authorization_response=callback, code_verifier=verifier)

    # Vérification du jeton d'accès avec la clé du JWKS, trouvée par son kid
    jwks = jwt.PyJWKClient(metadata["jwks_uri"])
    claims = jwt.decode(token["access_token"], jwks.get_signing_key_from_jwt(token["access_token"]).key,
                        algorithms=["RS256"], audience=args.client_id)
    print(f"access token vérifié : sub={claims['sub']}")

    userinfo = requests.get(metadata["userinfo_endpoint"], headers={"Authorization": f"Bearer {token['access_token']}"}, timeout=10)
    userinfo.raise_for_status()

    # Back-channel logout : le serveur envoie un logout token, vérifié avec le JWKS
    subprocess.run(args.revoke_command, shell=True, check=True, stdout=subprocess.DEVNULL)
    for _ in range(30):
        if receiver.tokens:
            break
        time.sleep(1)
    if not receiver.tokens:
        fail("aucun logout token reçu")
    logout_token = receiver.tokens[0]
    logout_claims = jwt.decode(logout_token, jwks.get_signing_key_from_jwt(logout_token).key, algorithms=["RS256"],
                               audience=args.client_id, issuer=metadata["issuer"])
    if "http://schemas.openid.net/event/backchannel-logout" not in logout_claims["events"]:
        fail("événement back-channel logout absent")
    print(f"logout token vérifié : sub={logout_claims['sub']}")
    print("OK")


if __name__ == "__main__":
    main()
