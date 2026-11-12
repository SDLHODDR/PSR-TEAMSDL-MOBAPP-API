# Non-field activities API

All endpoints require the employee bearer token and employee-access middleware.

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/non-field-activities/options` | Return active activity types other than Field Work, Holiday, Leave, and Sunday |
| GET | `/api/v1/non-field-activities` | List this employee's non-field activities from the last three months |
| POST | `/api/v1/non-field-activities` | Create or resubmit an activity for authorisation |

The create request contains `date` (`YYYY-MM-DD`), `activity_type`, and
`remarks`. To edit/resubmit a non-confirmed record, include its `activity_id`;
the saved date remains unchanged. Saves set status `T` and create/reuse an open
`NonFldAuth` manager task. Confirmed records cannot be edited.
