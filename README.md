# Test JEV Module

Sandbox Drupal 11 module for trying out JEV AI through the
[TypeSafe SystemOne API](https://docs.typesafe.ai/api).

## Setup

1. Enable the module: `ddev drush en test_jev_module -y`
2. Save your API key at **Configuration → JEV API → JEV API settings**
   (`/admin/config/jev/settings`). The key is stored in state, so it is never
   exported with config. Requires the *Administer JEV API* permission.

## Features

### SystemOne client

A service that calls `https://api.typesafe.ai/v1/systemone`:

```php
$client = \Drupal::service('test_jev_module.systemone_client');

$client->noul($text, 'Is this about sport?');                  // float, 0–1
$client->choice($text, 'Pick a topic', ['Sport', 'Tech']);     // ['choice' => 'tech', ...]
$client->score($text, 'How positive?', ['Bad', 'OK', 'Good']); // ['score' => 1.6, ...]
$client->ask($text, $questions);                               // several questions, one request
```

It retries rate-limited (429) and overloaded (529) requests up to 3 attempts,
and throws `SystemOneException` on errors. Requests, responses and errors are
logged to the `test_jev_module` channel (`/admin/reports/dblog`).

### "Suggest tag" widget

A field widget, **Check boxes/radio buttons with tag suggestion**, for
taxonomy term reference fields. The *Suggest tag* button sends the text of a
source field (default `field_body`) and the available terms to `choice()`, then
selects the suggested term. The source field and instructions can be set in
the widget settings on *Manage form display*.

### Computed comment fields

Two fields on comments, available in Views and shown on
`/admin/content/comment/approval`:

| Field | Method | Shows |
|---|---|---|
| `jev_positivity` | `score()` on 7 levels, Very negative to Very positive | Rating from 1/10 to 10/10 |
| `jev_needs_reply` | `noul()`: does the comment ask the author something? | Yes when probability > 0.7 |

Results are cached by comment text and question, so the API is only called
again when the text or question changes. A failed call shows "–" and is
retried on the next page load.

## Notes

- Viewing many unrated comments at once makes one API call per comment and
  field on the first load.
- Change the questions, levels and threshold in `src/CommentPositivity.php` and
  `src/CommentNeedsReply.php`.
