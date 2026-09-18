---
name: icons
description: Use when adding, finding or replacing an icon in an it4web project — Font Awesome Pro SVGs are copied by hand from the shared FontAwesomeCache mirror into Blade components, never installed via npm or loaded from a CDN.
---

# Icons

Icons are not an npm dependency. The full Font Awesome Pro package is mirrored once per
workstation in a shared repository, and icons are copied out of it into Blade components by
hand. No project has a Font Awesome credential, and no project downloads the package.

## One-time setup per workstation

```bash
git clone git@github.com:IT4WEBBV/FontAwesomeCache.git \
  ~/GitProjects/FontAwesomeCache/FontAwesomeCache
```

Keep it current with `git -C ~/GitProjects/FontAwesomeCache/FontAwesomeCache pull`. A pull
is a force-push fast-forward failure by design — the mirror rewrites `main` on every Font
Awesome release — so use `git fetch origin && git reset --hard origin/main` when a plain
pull refuses.

## Adding an icon

Find the SVG in the mirror:

```bash
cat ~/GitProjects/FontAwesomeCache/FontAwesomeCache/fa/svgs/{style}/{name}.svg
```

Styles: `solid`, `regular`, `light`, `thin`, `duotone`, `brands`, plus the `sharp-*` and
`duotone-*` families — 17 in all.

Use `fa/svgs/`, not `fa/svgs-full/`. The trimmed variant carries a per-icon viewBox
(`0 0 448 512` for `solid/user`), which is what the existing Blade components are built
from; `svgs-full/` normalises everything to `0 0 640 640` and renders at a different
effective size.

Then create `resources/views/components/icon/{name}.blade.php`, copying the `viewBox` and
the `d` attribute from that file. Projects carry their own rules for how the component is
written — check the project `CLAUDE.md` before adding one.
