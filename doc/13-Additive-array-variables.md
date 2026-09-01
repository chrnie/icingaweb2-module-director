<a id="Additive-array-variables"></a>Additive custom variables (`+=`)
=====================================================================

Custom variables are inherited from imported templates. By default the value
of the most specific object wins: an object either **overrides** an inherited
value or leaves it untouched.

That is fine for strings and numbers, but often too coarse for arrays. A
typical example is a list of mount points that `check_disk` should ignore: a
base template defines the ones every host should skip, and single hosts want
to **add** one or two more without repeating the whole list.

The Icinga 2 DSL knows the operators `+=` and `-=` for exactly this, and the
Director is able to render them.

How it looks
------------

Given a template:

```
template Host "linux-base" {
    vars.disk_ignore = [ "/proc", "/sys" ]
}
```

A host adding a single path:

```
object Host "web01" {
    import "linux-base"

    vars.disk_ignore += [ "/var/lib/docker" ]
}
```

Icinga 2 resolves `vars.disk_ignore` to `[ "/proc", "/sys", "/var/lib/docker" ]`.
The Director's preview and its resolved object view show the very same result.

Configuring it in the GUI
-------------------------

Every entry of an array valued custom variable carries its **own operator**,
shown as a small button in front of it. Clicking the button cycles through:

* `=` - this entry replaces whatever has been inherited (the default)
* `+` - this entry is added to the inherited value
* `-` - this entry is removed from the inherited value

Inherited values used to be shown as a hint only, so overriding an array meant
retyping all of its entries. They are now **prefilled as regular entries** with
`=`, each one with its own remove button - but only while the object carries no
own value. Switching one of them to `-` is all it takes to get rid of a single
inherited entry.

Prefilled entries are not silently turned into an own value: as long as you do
not touch them, the variable keeps being inherited. And as soon as any entry
uses `+` or `-`, untouched prefilled entries are dropped - they just show what
is inherited anyways, and keeping them would replace the inherited value.

Mixing `=` with `+` or `-` is refused when saving. Icinga 2 would either replace
an array or modify it, so combining both makes no sense. `+` and `-` may of
course be combined, this is what gives you two lines.

The operator per entry is how one **edits** such a variable, not how it is
stored. What gets saved is either the plain array or the two sets of entries to
add respectively remove - see below for how they look in the API.

Semantics
---------

The Director resolves the value the same way Icinga 2 does at runtime:

| Entries | Rendered as                                     |
|---------|-------------------------------------------------|
| only `=` | `vars.x = [ ... ]`, exactly as before          |
| only `+` | `vars.x += [ ... ]`                            |
| only `-` | `vars.x -= [ ... ]`                            |
| `+` and `-` | both lines, additions first                 |

An entry never ends up in the array twice. Icinga 2 concatenates arrays without
removing duplicates, so the Director ships only the entries that are not
inherited already, and identical entries are collapsed into one.

Nothing inherited? `+=` and `-=` are still valid, Icinga 2 treats a missing
value as an empty array. So there is no need to guarantee that a parent
template defines the variable.

Multiple levels of inheritance are combined from the least to the most specific
template, following the very same order Icinga 2 uses.

Import and Sync
---------------

Sync rules can maintain additive variables as well. When a sync property writes
a custom variable, an **Assignment** selector offers `=`, `+=` and `-=`. The
chosen operator is applied to every entry the rule writes.

Picking `+=` or `-=` also turns a single imported value into a single array
entry, so there is no need to build an array first just to add one tag.

Please note that a sync rule owns the variables it writes. If a rule syncs
`vars.disk_ignore`, it also defines its operator, overwriting whatever has been
configured manually for that variable.

This is independent of the already existing `vars.whatever[]` destination
syntax, which merges **several imported rows** into one array within a single
sync run. Both can be combined: collect the values from your import source with
`[]`, and let the result extend the templates with `+=`.

REST API, CLI and Baskets
-------------------------

A variable is **either assigned or extended**, never both. What it adds and
what it removes lives in an additional `var_deltas` property, one bucket each.
An extended variable has no own value, so it ships the empty array in `vars`:

```json
{
    "object_name": "web01",
    "object_type": "object",
    "imports": [ "linux-base" ],
    "vars": {
        "disk_ignore": []
    },
    "var_deltas": {
        "disk_ignore": {
            "add":    [ "/var/lib/docker" ],
            "remove": [ "/proc" ]
        }
    }
}
```

`vars` stays complete on purpose. Assigning it is authoritative - it drops every
variable it does not mention - and the Director replays object properties
through this very shape internally, on every single load of a branched object.
A variable missing from `vars` is therefore a variable being deleted, never one
that merely moved to `var_deltas`.

This renders as:

```
vars.disk_ignore += [ "/var/lib/docker" ]
vars.disk_ignore -= [ "/proc" ]
```

Empty buckets are left out, and the property is omitted completely when no
variable extends anything - so existing integrations, baskets and diffs are not
affected.

Sending both an assignment and a delta for the same variable is refused with
`Custom variable "disk_ignore" is assigned [...], so it cannot also extend an
inherited value`. The same goes for adding and removing the very same entry, and
for buckets other than `add` and `remove` - a typo does not silently do nothing.

A full object definition is authoritative: assigning `vars` drops the delta of
every variable `var_deltas` does not mention. The order in which both properties
are shipped does not matter - and it must not, as sorted output puts `var_deltas`
in front of the `vars` it belongs to.

Limitations
-----------

* Operators are available for arrays only. Dictionaries and scalar values are
  always overridden, as Icinga 2 refuses to subtract dictionaries and adding up
  strings or numbers is rarely what one wants
* Editing many objects at once (multi-edit) does not offer operators, values
  only
* Duplicates are suppressed against everything the Director knows about. An
  entry also shipped by a template not managed by the Director could still end
  up twice
* The host list previewing which hosts match an *Apply Rule* filter uses a
  separate, simplified resolver. It does not combine additive variables yet, so
  a filter on such a variable may match differently there than it does in the
  generated configuration

Related upstream issues
-----------------------

* [#1605](https://github.com/Icinga/icingaweb2-module-director/issues/1605)
  Custom Variable inheritance for array-type variable
* [#2729](https://github.com/Icinga/icingaweb2-module-director/issues/2729)
  Inheritance of arrays in templates
* [#2214](https://github.com/Icinga/icingaweb2-module-director/issues/2214)
  Overwrite or union data fields
* [#3102](https://github.com/Icinga/icingaweb2-module-director/pull/3102)
  brings the very same operators to group memberships
