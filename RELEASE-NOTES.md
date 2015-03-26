These are the release notes for the [MediaWiki][mediawiki] [GraphViz extension][gv_ext].

## GraphViz 1.6.0 ## (2015-3-26 )
* Make the category pages created by this extension optional, non-empty and do not tag “dummy” images as belonging to a category.
* Allow DOT tooltips without URLs.

## GraphViz 1.5.1 ## (2015-1-24)
* Fix for [bug T75073](https://phabricator.wikimedia.org/T75073).

## GraphViz 1.5.0 ## (2014-10-28)
* Add tag arguments preparse="dynamic" and preparse="static".
* Add categories for pages created by this extension.

## GraphViz 1.4.1 ## (2014-10-21)
* Fix for [bug 72325](https://bugzilla.wikimedia.org/show_bug.cgi?id=72325).

## GraphViz 1.4.0 ## (2014-10-19)
* Add unit test hook, first unit test.
* Avoid reload message ("Graph image source changed. Reload page to display updated graph image.")
* Add COPYING notice.

## GraphViz 1.3.1 ## (2014-07-07)
* Fix for [bug 67587](https://bugzilla.wikimedia.org/show_bug.cgi?id=67587).

## GraphViz 1.3.0 ## (2014-06-30)
* Added README.md and RELEASE-NOTES.md.

## GraphViz 1.2.0 ## (2014-06-25)

### Compatibility changes
* Added suggestion for [Composer][composer] [mediawiki/image-map package](https://packagist.org/packages/mediawiki/image-map).

## GraphViz 1.1.0 ## (2014-06-13)

### Compatibility changes
* Installation is now done via the [Composer][composer] dependency manager using the [mediawiki/graph-viz package](https://packagist.org/packages/mediawiki/graph-viz).

## GraphViz 1.0.0 ## (2014-05-28)

### Dependency changes
* added dependency on [MediaWiki][mediawiki] [ImageMap extension][image_map_ext].

### Compatibility changes
* global function renderGraphviz() replaced by GraphViz::graphvizParserHook
* global function renderMscGen() replaced by GraphViz::mscgenParserHook
* new link syntax is given [here](https://www.mediawiki.org/wiki/Extension:GraphViz#Links)

### New features
* rendered graph and message sequence chart images are uploaded to the wiki
* graphs and message sequence charts are only re-rendered when the source changes
* embedded links work properly when the rendered image is resized (powered by [ImageMap][image_map_ext])
* embedded links support tooltips (powered by [ImageMap][image_map_ext])
* support for the DOT [image attribute](http://www.graphviz.org/content/attrs#dimage image)
* deterministic file clean-up (active files are retained, inactive files are deleted)
* support for multiple message sequence charts per page (uniquifier)
* security fixes
* extensive internal documentation (doxygen format)

## GraphViz 0.9.0 (and prior) ## (2011-03-14)
* In 2011 Jeroen De Dauw uploaded the latest code into [MediaWiki SVN](http://svn.wikimedia.org/viewvc/mediawiki/trunk/extensions/GraphViz/ MediaWiki SVN).
* In 2010 Thomas Hummel merged versions, along with his own fixes, to try to create a working solution for several OSes in one file.
* In 2008 Matthew Pearson created the extension GraphVizAndMSCGen, combining the code from the GraphViz extension with the MscGen extension.
* In 2006 Gregory Szorc independently created his own "Graphviz" (small "v") extension that included some helpful features like autopruning.
* Also in 2006, Ruud Schramp created the MscGen extension, adapting the code from the GraphViz extension to work with MscGen.
* In 2004 Coffman created an extension to MediaWiki in response to a basic need: rendering graphs online. He found the utility Graphviz in use on another wiki application, and thought about adapting it for MediaWiki (the wiki he actually used). Exploring the Graphviz tool, he discovered an incredible tool for making graphs.  Later on, many people improved the extension on their own or provided little bug fixes as snippets on the discussion page. This led to several functional solutions for different use cases, and to a bit of chaos.

[mediawiki]: https://www.mediawiki.org/wiki/MediaWiki
[gv_ext]: https://www.mediawiki.org/wiki/Extension:GraphViz
[image_map_ext]: https://www.mediawiki.org/wiki/Extension:ImageMap
[composer]: http://getcomposer.org/

