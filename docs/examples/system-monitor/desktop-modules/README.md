# Desktop modules for this app

Drop your signed desktop module package here as `*.pkg.json`. On app
activation, Basehim core auto-registers each package (owner = this app's
slug) and pushes the auto_install ones to connected agents.

Build a signed package with the Circuits-DIY Engine tools:

    node tools/sign-module.js --module ./sysmon-module --key ./keys/module-signing.private.pem --out sysmon.pkg.json

Optional sidecar `sysmon.meta.json`: { "name": "System Metrics Collector", "auto_install": true }

The desktop app verifies the signature against a trusted key before installing,
so shipping the package inside a app zip is safe.
