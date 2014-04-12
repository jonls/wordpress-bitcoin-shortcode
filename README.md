
Bitcoin Shortcode plugin
========================

This Wordpress plugin providing a shortcode for accepting payment of bitcoins
through a payment processor. Currently Coinbase is supported as a payment
processor, and an account at Coinbase is required.

Example shortcode:

```
[bitcoin code="81c71f54a9579902c2b0258fc29d368f"]
```

The shortcode will insert a small button that links to the payment page at the
payment processor. The button is loaded through an iframe to avoid clashes with
existing style sheets. Further info can be shown to the right of the button in a
bubble.

To show the number of transactions to the payment page:

```
[bitcoin code="81c71f54a9579902c2b0258fc29d368f" info="count"]
```

To show the amount received by the payment page:

```
[bitcoin code="81c71f54a9579902c2b0258fc29d368f" info="received"]
```

To show no info other than the button:

```
[bitcoin code="1GRJ6119tmKDUfr8HWR4VaYrvJ6oKns9jp" info="none"]
```

Widgets
-------
It is also possible to use shortcodes in widgets but this requires an additional
plugin to be installed
(e.g http://wordpress.org/extend/plugins/shortcodes-in-sidebar-widgets/).

Demo
----
Here is a demo of the plugin:
http://jonls.dk/2013/03/trying-out-bitcoin-wordpress-plugin/

Install
-------
Copy the directory `bitcoin-button` into `/wp-content/plugins`.
