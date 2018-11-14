# Orders

When a cart is completed, it becomes an order. You can view orders in the Commerce → Orders section of the Control Panel. 

When a cart becomes an order the following things happen:

1) The order gets a `dateOrdered` date.
2) The `isCompleted` order attribute is set to `true`.
3) The default [order status](custom-order-statuses.md) is set on the order and any emails for this status are sent.
4) A reference number is generated for the order based on the Order Reference Number Format in the general settings.

## Order Number

The order number is a hash generated when the cart is created. It exists from initial creation for the entire life of the order.  
This is different to the order reference number that is only generated after the cart has been completed and becomes an order. 

## Order Reference Number

The Order Reference Number is generated on cart completion by the "Order Reference Number Format" in general settings.

The "Order Reference Number Format" is a mini Twig template, which will be rendered when the order is completed.

Attributes on the order can be accessed as well as twig filters and functions, for example:

```twig
{{ dateCompleted|d('y') }}-{{ id }}
```

Please note in the above example, the ID is the element ID, which is not sequential.

A sequential number can be generated by the use of Craft’s sequence (`seq('mySequenceName')`) function, which generates a next unique number based on the `name` parameter passed to it.

The sequence function gets the next number in the sequence based on the name. To learn more about the s

The `seq()` function takes the following parameters:

1. A key name. If this name is changes, a new sequence starting at one is started. See Craft docs for more information.
2. An optional padding character length. For example if the next sequence number is `14` and the padding length is `8`, the generation number will be `00000014` 

For example:
```twig
{{object.dateCompleted|date('y')}}-{{ seq(object.dateCompleted|date('y'), 8) }}
```
In the above example we have used the year as the sequence name so that we automatically get a new sequence starting at 1 when the next year arrives.
