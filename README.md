Drupal Dependency Quality Gate (DDQG)
---

This project aims to help run Drupal projects on secure and high-quality Drupal dependencies.

**CHECK OUT** the [mxr576/ddqg-composer-audit](https://packagist.org/packages/mxr576/ddqg-composer-audit) package that
extends `composer audit` command with advisories originating from the `^dev-no-no-[a-zA-Z]+-versions$` releases.

## Releases

Releases of this package that matches the `^dev-no-no-[a-zA-Z]+-versions$` regex ensure that your project
doesn't have installed dependencies with known quality problems.

<img alt="Family Guy, Consuela says: No, no, no low quality dependencies" height="250" src="https://i.imgflip.com/7ijrpx.jpg"/>

```shell
$ composer require --dev mxr576/ddqg:[dev-no-no-insecure-versions|dev-no-unsupported-versions]
```

* `dev-no-no-insecure-versions`: Project releases (versions) with public security advisories (PSAs)
  * Pretty much what `drupal-composer/drupal-security-advisories` does today.
* `dev-no-no-unsupported-versions`: Unsupported projects by maintainers or and unsupported project
  releases (versions) by project maintainers or the Drupal Security team.
  * Inspired by: https://github.com/drupal-composer/drupal-security-advisories/issues/29
* [PLANNED] An opinionated list of projects that should be avoided

## TODOs

* Ignore releases with Drupal 7 compatibility as there is no plan to support Drupal 7
