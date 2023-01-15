# WP CMS | EXPERIMENTAL!

**Note**: this project may be merged into ClassicPress [(see experimental repository)](https://github.com/ClassicPress/wp-cms-experimental), and serve as a base for a new version of it. ClassicPress is more mature and if it's going to use this experimental fork as a base, it will make more sense to just work together as one.

---

Welcome to the WP CMS development repository! This is a forked version of WordPress which doesn't include any Gutenberg, FSE or Blocks related code. For everything else, this is still the WordPress you know. PHP enhancements, bug fixes and overall improvements are synced with WordPress almost every day.

## Target users

WordPress is well-known for being easy to use and lowering the entry barrier for web development. Anyone can create a website without ever having to touch code. That is wonderful. But, when you are a developer... that sometimes gets in the way of your work. All this Gutenberg, emojis that get forced into your site, Full Site Editing... it feels like crap when you want to create something solid with a predefined markup that doesn't have to come in the form of blocks through a page builder.

That is why WP CMS is targeted towards developers. Think of WP CMS more like a framework that you use to develop sites using PHP as a backend and whatever frontend tools and languages you enjoy using. You will have to code your own theme and plugins, using the easy and amazing "WP Way" of doing things.

## Do WordPress themes and plugins still work?

Sure, if they don't require blocks related code to function, they will work perfectly. But if you are the kind of developer who relies on third party plugins for everything, then I recommend you to stick with WordPress instead.

This project is aimed towards users who know what they are doing. Id est:

* They want to use a custom theme made specifically for their project.
* They want to develop plugins that are specific to what they are building.
* They may rely on third party plugins that complement their needs.

## Why use this when I can just use something modern like CraftCMS?

The idea is to keep everything as close to WordPress as possible, because nothing beats WordPress in terms of ease and online available resources.

There are tons of tutorials and open sourced plugins out there that you can use as a base for anything you want to build. Literally... anything. CraftCMS and other platforms are amazing, but modern doesn't always mean better.

WP CMS is here to cover that gap between using a kids toy like WordPress or an adults toy like CraftCMS. It's the toy that fun developers enjoy using.

## Development notes

For now, I'm working on this alone, using three branches:

* **main** - protected, everything has to be merged through a Pull Request coming from one of the two following branches:
  * **moving-away-from-wordpress** - Remove blocks related code, introduce changes.
  * **moving-along-with-wordpress** - Cherrypick back from [WordPress/wordpress-develop](https://github.com/WordPress/wordpress-develop).

Have ideas? Want to help?

Issues and PRs welcome!

## Roadmap

### Short term

The fundamental scope of this project is to come up with a Blocks-free version of WordPress.

### Long term

From the first released version, WP CMS requires at least PHP 7.4, which in the long term will allow to optimize core and refactor without having to support ancient PHP...

That's something that WordPress won't be able to do for a long time, because they do a great job in staying backwards compatible.

And that's the advantage of this fork.

It's a fresh start for developers who _love_ WordPress and at the same time _hate_ WordPress.

---

# Original WordPress readme

(I keep this updated with upstream too, of course)

* [Getting Started](#getting-started)
* [Credentials](#credentials)

## Getting Started

WordPress is a PHP, MySQL, and JavaScript based project, and uses Node for its JavaScript dependencies. A local development environment is available to quickly get up and running.

You will need a basic understanding of how to use the command line on your computer. This will allow you to set up the local development environment, to start it and stop it when necessary, and to run the tests.

You will need Node and npm installed on your computer. Node is a JavaScript runtime used for developer tooling, and npm is the package manager included with Node. If you have a package manager installed for your operating system, setup can be as straightforward as:

* macOS: `brew install node`
* Windows: `choco install nodejs`
* Ubuntu: `apt install nodejs npm`

If you are not using a package manager, see the [Node.js download page](https://nodejs.org/en/download/) for installers and binaries.

**Note:** WordPress currently only supports Node.js `14.x` and npm `6.x`.

You will also need [Docker](https://www.docker.com/products/docker-desktop) installed and running on your computer. Docker is the virtualization software that powers the local development environment. Docker can be installed just like any other regular application.

### Development Environment Commands

Ensure [Docker](https://www.docker.com/products/docker-desktop) is running before using these commands.

#### To start the development environment for the first time

Clone the current repository using `git clone https://github.com/wp-cms/wp-cms.git`. Then in your terminal move to the repository folder `cd wp-cms` and run the following commands:

```
npm install
npm run build:dev
npm run env:start
npm run env:install
```

Your WordPress site will accessible at http://localhost:8889. You can see or change configurations in the `.env` file located at the root of the project directory.

#### To watch for changes

If you're making changes to WordPress core files, you should start the file watcher in order to build or copy the files as necessary:

```
npm run dev
```

To stop the watcher, press `ctrl+c`.

#### To run a [WP-CLI](https://make.wordpress.org/cli/handbook/) command

```
npm run env:cli -- <command>
```

WP-CLI has [many useful commands](https://developer.wordpress.org/cli/commands/) you can use to work on your WordPress site. Where the documentation mentions running `wp`, run `npm run env:cli --` instead. For example:

```
npm run env:cli -- help
```

#### To run the tests

These commands run the PHP and end-to-end test suites, respectively:

```
npm run test:php
npm run test:e2e
```

#### To restart the development environment

You may want to restart the environment if you've made changes to the configuration in the `docker-compose.yml` or `.env` files. Restart the environment with:

```
npm run env:restart
```

#### To stop the development environment

You can stop the environment when you're not using it to preserve your computer's power and resources:

```
npm run env:stop
```

#### To start the development environment again

Starting the environment again is a single command:

```
npm run env:start
```

## Credentials

These are the default environment credentials:

* Database Name: `wordpress_develop`
* Username: `root`
* Password: `password`

To login to the site, navigate to http://localhost:8889/wp-admin.

* Username: `admin`
* Password: `password`

To generate a new password (recommended):

1. Go to the Dashboard
2. Click the Users menu on the left
3. Click the Edit link below the admin user
4. Scroll down and click 'Generate password'. Either use this password (recommended) or change it, then click 'Update User'. If you use the generated password be sure to save it somewhere (password manager, etc).
