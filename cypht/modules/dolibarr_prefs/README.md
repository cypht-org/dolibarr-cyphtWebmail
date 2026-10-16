# dolibarr_prefs

Applies the preferences Dolibarr already holds for a user to their webmail
account at login, so the two halves of the application agree.

## What it applies

`CyphtLogin::exportPreferences()` puts the values in the environment before
`cypht_login()`, and these `functional_api` handlers copy them into the user's
Cypht settings:

| Handler | Environment | Cypht setting | Setup page option |
| --- | --- | --- | --- |
| `dolibarr_apply_language` | `DOLIBARR_USER_LANG` | `language_setting` | Language |
| `dolibarr_apply_theme` | `DOLIBARR_USER_THEME`, `DOLIBARR_USER_THEME_AUTO` | `theme_setting` | Light or dark |
| `dolibarr_apply_compose_type` | `DOLIBARR_USER_COMPOSE_TYPE` | `smtp_compose_type_setting` | Message format |

An empty value means the setup page leaves the choice to the user, and the
handler does nothing.

"Follow the browser" dark mode adds the dark stylesheet with
`media="(prefers-color-scheme: dark)"`, from `Hm_Output_dolibarr_theme_auto`.

## Switching users

`Hm_Output_dolibarr_session_user` clears Cypht's browser storage when the
Dolibarr login changes, so one user never sees another's cached lists.
