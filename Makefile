# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

app_name := $(shell sed -n 's:.*<id>\(.*\)</id>.*:\1:p' appinfo/info.xml | head -n 1)
version := $(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
build_dir := build
artifact := $(build_dir)/artifacts/$(app_name)-$(version).tar.gz

# App key pair used to sign the release. It lives outside the repository on purpose: the
# private key identifies you as the publisher, and losing it means you cannot ship updates
# for an app that is already listed in the store.
key ?= $(HOME)/.nextcloud/$(app_name).key
crt ?= $(HOME)/.nextcloud/$(app_name).crt
# How to reach occ. On the docker-compose development setup that is
# `docker exec -u www-data nextcloud php occ`; the build directory then has to be inside
# the container as well, which is what nc_build_dir is for.
nc_occ ?= occ
nc_build_dir ?= $(abspath $(build_dir))

.PHONY: all build check-manifest appstore sign keypair clean

all: build

# Production frontend build. Skipping this ships the plain-JavaScript page instead.
build:
	npm ci
	npm run build

# Validate appinfo/info.xml against the app store schema (needs xmllint: libxml2-utils on
# Debian/Ubuntu, preinstalled on macOS). Also catches TODO placeholders left by rename.sh.
check-manifest:
	mkdir -p $(build_dir)
	curl -sSfo $(build_dir)/info.xsd https://apps.nextcloud.com/schema/apps/info.xsd
	xmllint --noout --schema $(build_dir)/info.xsd appinfo/info.xml

# Release tarball: the app directory minus everything in .nextcloudignore, with the
# top-level directory named after the app id (what the app store and occ expect).
appstore: check-manifest build
	rm -rf $(build_dir)/$(app_name)
	mkdir -p $(build_dir)/artifacts $(build_dir)/$(app_name)
	rsync -a --exclude-from=.nextcloudignore ./ $(build_dir)/$(app_name)/
	tar -czf $(artifact) -C $(build_dir) $(app_name)
	@echo "$(artifact)"
	@tar -tzf $(artifact) | grep -cE '\.(php|mjs|css|xml)$$' | xargs printf '%s runtime files packaged\n'

# One-time: create the key pair that signs every release of this app.
keypair:
	mkdir -p $(dir $(key))
	openssl req -x509 -newkey rsa:4096 -keyout $(key) -out $(crt) -days 3650 -nodes \
		-subj "/CN=$(app_name)"
	@echo "keep $(key) private and backed up; upload $(crt) to your app store account"

# Signed release: the store only accepts archives that carry appinfo/signature.json, and
# occ writes that file into the *unpacked* directory — so sign after packaging, then
# rebuild the archive.
sign: appstore
	$(nc_occ) integrity:sign-app \
		--privateKey=$(key) \
		--certificate=$(crt) \
		--path=$(nc_build_dir)/$(app_name)
	rm -f $(artifact)
	tar -czf $(artifact) -C $(nc_build_dir) $(app_name)
	@echo "signed: $(artifact)"

clean:
	rm -rf $(build_dir) node_modules vendor
