
Bitcoin Shortcode plugin
========================

This Wordpress plugin providing a shortcode for accepting payment of bitcoins
through a payment processor. Currently Coinbase is supported as a payment
processor, and an account at Coinbase is required.

Example shortcode:

```
[bitcoin id="my-widget"]
```

The shortcode will insert a small button that links to the payment page at the
payment processor. The button is loaded through an iframe to avoid clashes with
existing style sheets. This iframe can also be embedded in external web sites
that do not use Wordpress. Further info can be shown to the right of the button
in a bubble.

Go to the Bitcoin Shortcode settings page to set up widgets. Follow the
instructions to create a new Coinbase payment page and enter an ID for the
widget that will be used in the shortcode.

Widgets
-------
It is also possible to use shortcodes in Wordpress widgets (e.g. in the side
bar) but this requires an additional plugin to be installed (e.g
http://wordpress.org/extend/plugins/shortcodes-in-sidebar-widgets/).

Demo
----
Here is a demo of the plugin:
http://jonls.dk/2013/03/trying-out-bitcoin-wordpress-plugin/

Install
-------
Copy the directory `bitcoin-button` into `/wp-content/plugins`.
