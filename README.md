Drupal Dependency Quality Gate (DDQG)
---

This project aims to help run Drupal projects on secure and high-quality Drupal dependencies.

## Quality Levels

`dev-quality-level-N` versions of this package ensure that your project doesn't have installed dependencies with
known quality problems.

```shell
$ composer require --dev mxr576/ddqg:dev-quality-level-1 (2, 3...)
```

* [PLANNED] `quality-level-1`: Project releases (versions) with public security advisories (PSAs)
  * Pretty much what `drupal-composer/drupal-security-advisories` does today.
* `quality-level-2`: Level 1 + Unsupported projects or project releases
  * Inspired by: https://github.com/drupal-composer/drupal-security-advisories/issues/29
* [PLANNED] Level-TBD: Level 1 + Level 2 + ... + An opinionated list of projects that should be avoided
* `quality-level-max`: `sum(level-1,...)`

## Planned features

* A `composer validate` plugin to ensure only high-quality dependencies are used on a project
* A custom Composer command for analyzing locked dependencies on a project
