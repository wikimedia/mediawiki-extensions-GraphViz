# GraphViz
[![Latest Stable Version](https://poser.pugx.org/mediawiki/graph-viz/version.png)](https://packagist.org/packages/mediawiki/graph-viz)
[![Packagist download count](https://poser.pugx.org/mediawiki/graph-viz/d/total.png)](https://packagist.org/packages/mediawiki/graph-viz)

The [MediaWiki][mediawiki] [GraphViz extension][gv_ext] lets users collaborate to create and display graphs and message sequence charts as in-line images on wiki pages using tools from the open-source [Graphviz][graphviz] and [Mscgen][mscgen] projects.  For more information, consult the [release notes](RELEASE-NOTES.md).

## Requirements

- PHP 5.3 or later
- MediaWiki 1.19 or later

## Installation

The recommended way to install this extension is by using [Composer][composer]. Just add the following to the MediaWiki `composer.json` file and run the ``php composer.phar install/update`` command.

```json
{
	"require": {
		"mediawiki/graph-viz": "~1.1"
	}
}
```

## Contribution and support

Development is coordinated by Keith Welter and Jeroen De Dauw.

If you have remarks, questions, or suggestions, please add a topic to the [GraphViz extension talk page][talk].

If you want to contribute work to the project, start by reading the [MediaWiki hacker tutorial][hacker]. A list of people who have made contributions in the past can be found [here][contributors].

To report a bug, go [here](https://bugzilla.wikimedia.org/enter_bug.cgi?product=MediaWiki%20extensions&format=guided).

## License

Generally published under [GNU General Public License 2.0 or later][license] together with third-party plugins and their license.

[mediawiki]: https://www.mediawiki.org/wiki/MediaWiki
[gv_ext]: https://www.mediawiki.org/wiki/Extension:GraphViz
[graphviz]: https://github.com/ellson/graphviz
[mscgen]: http://www.mcternan.me.uk/mscgen/
[composer]: https://getcomposer.org/
[talk]: https://www.mediawiki.org/wiki/Extension_talk:GraphViz
[hacker]: https://www.mediawiki.org/wiki/How_to_become_a_MediaWiki_hacker/Extension_Writing_Tutorial
[contributors]: https://github.com/mediawiki-extensions-GraphViz/graphs/contributors
[license]: https://www.gnu.org/copyleft/gpl.html
