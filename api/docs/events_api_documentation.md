# Calendar Events REST API Documentation

## Base URL
```
/api/events.php
```

## Authentication
All endpoints require authentication via session cookies. Users must be logged in through the `/api/auth.php` endpoint first.

## Response Format
All responses follow this standard format:

```json
{
    "success": boolean,
    "data": {} | [] | null,
    "message": "Human-readable message",
    "metadata": {
        "timestamp": "ISO 8601 format",
        // Additional metadata
    }
}
```

## HTTP Status Codes
- `200` - Success
- `201` - Created
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `409` - Conflict
- `500` - Internal Server Error

---

## Core Endpoints

### 1. Get Events in Date Range

**Endpoint:** `GET /api/events.php`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| start | string | Yes | Start date (ISO 8601) |
| end | string | Yes | End date (ISO 8601) |
| calendar_id | integer | No | Filter by calendar |
| participant_id | integer | No | Filter by participant |
| include_recurring | boolean | No | Include recurring events (default: true) |
| timezone | string | No | Convert times to timezone |
| category | string | No | Filter by category |
| location | string | No | Filter by location |

**Example Request:**
```
GET /api/events.php?start=2024-01-01T00:00:00Z&end=2024-01-31T23:59:59Z&calendar_id=1
```

**Example Response:**
```json
{
    "success": true,
    "data": {
        "events": [
            {
                "id": 1,
                "title": "Team Meeting",
                "description": "Weekly standup",
                "start": "2024-01-15T10:00:00Z",
                "end": "2024-01-15T11:00:00Z",
                "is_all_day": false,
                "location": "Conference Room A",
                "calendar": {
                    "id": 1,
                    "name": "Team Calendar",
                    "color": "#3B82F6"
                },
                "participants": [
                    {
                        "user_id": 1,
                        "name": "John Doe",
                        "email": "john@example.com",
                        "status": "accepted",
                        "is_organizer": true
                    }
                ],
                "recurrence": {
                    "pattern": "FREQ=WEEKLY;BYDAY=MO",
                    "is_recurring": true,
                    "parent_id": null
                },
                "reminders": [
                    {
                        "type": "email",
                        "minutes_before": 15
                    }
                ],
                "category": "meeting",
                "status": "confirmed",
                "can_edit": true
            }
        ],
        "total": 42
    },
    "message": "Events retrieved successfully",
    "metadata": {
        "timestamp": "2024-01-15T12:00:00Z",
        "start": "2024-01-01T00:00:00Z",
        "end": "2024-01-31T23:59:59Z",
        "filters_applied": true
    }
}
```

---

### 2. Get Single Event

**Endpoint:** `GET /api/events.php?id={id}`

**Example Request:**
```
GET /api/events.php?id=123
```

---

### 3. Create Event

**Endpoint:** `POST /api/events.php`

**Request Body:**
```json
{
    "title": "Project Review",
    "description": "Quarterly review meeting",
    "start": "2024-01-20T14:00:00Z",
    "end": "2024-01-20T16:00:00Z",
    "timezone": "America/New_York",
    "is_all_day": false,
    "location": "Zoom",
    "calendar_id": 1,
    "participants": [
        {
            "user_id": 2,
            "required": true
        },
        {
            "email": "external@client.com",
            "required": false
        }
    ],
    "recurrence": "FREQ=MONTHLY;BYMONTHDAY=20",
    "reminders": [
        {
            "type": "email",
            "minutes_before": 1440
        },
        {
            "type": "notification",
            "minutes_before": 15
        }
    ],
    "category": "review",
    "attachments": [1, 2, 3]
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        // Created event object
    },
    "message": "Event created and invitations sent"
}
```

---

### 4. Update Event

**Endpoint:** `PUT /api/events.php?id={id}`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| id | integer | Yes | Event ID |
| notify_participants | boolean | No | Send notifications (default: true) |

**Request Body:** Same as POST but partial updates allowed

---

### 5. Delete Event

**Endpoint:** `DELETE /api/events.php?id={id}`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| id | integer | Yes | Event ID |
| delete_series | boolean | No | Delete entire series (default: false) |
| notify_participants | boolean | No | Send cancellation (default: true) |

---

## Additional Actions

### 6. Respond to Invitation

**Endpoint:** `POST /api/events.php?action=respond&id={id}`

**Request Body:**
```json
{
    "response": "accepted", // accepted, declined, tentative
    "message": "Looking forward to it!"
}
```

---

### 7. Check Conflicts

**Endpoint:** `GET /api/events.php?action=conflicts`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| start | string | Yes | Start time |
| end | string | Yes | End time |
| participants[] | array | No | User IDs to check |

**Response:**
```json
{
    "success": true,
    "data": {
        "has_conflicts": true,
        "conflicts": [
            {
                "event_id": 123,
                "title": "Existing Meeting",
                "start": "2024-01-15T10:00:00Z",
                "end": "2024-01-15T11:00:00Z",
                "type": "time_conflict",
                "severity": "high"
            }
        ],
        "total": 1
    },
    "message": "Conflicts detected"
}
```

---

### 8. Get User Availability

**Endpoint:** `GET /api/events.php?action=availability`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| user_id | integer | Yes | User ID |
| date | string | Yes | Date to check |

**Response:**
```json
{
    "success": true,
    "data": {
        "date": "2024-01-15",
        "work_hours": {
            "start": "09:00",
            "end": "18:00"
        },
        "busy_slots": [
            {
                "start": "10:00",
                "end": "11:00"
            }
        ],
        "free_slots": [
            {
                "start": "09:00",
                "end": "10:00"
            },
            {
                "start": "11:00",
                "end": "18:00"
            }
        ],
        "total_free_minutes": 480
    }
}
```

---

### 9. Get Meeting Suggestions

**Endpoint:** `GET /api/events.php?action=suggestions`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| duration | integer | Yes | Duration in minutes |
| participants[] | array | Yes | User IDs |
| date_range_start | string | Yes | Start of range |
| date_range_end | string | Yes | End of range |
| preferred_times | array | No | Preferred times |
| avoid_lunch | boolean | No | Avoid lunch hours |
| skip_weekends | boolean | No | Skip weekends |
| max_suggestions | integer | No | Max results (default: 10) |

**Response:**
```json
{
    "success": true,
    "data": {
        "suggestions": [
            {
                "start": "2024-01-16T14:00:00Z",
                "end": "2024-01-16T15:00:00Z",
                "score": 95,
                "reasons": [
                    "All participants available",
                    "Preferred afternoon time"
                ],
                "conflicts": [],
                "participants_available": 3
            }
        ],
        "total": 5
    }
}
```

---

### 10. Duplicate Event

**Endpoint:** `POST /api/events.php?action=duplicate&id={id}`

**Request Body:**
```json
{
    "title": "New Title (optional)",
    "start": "2024-02-01T10:00:00Z",
    "end": "2024-02-01T11:00:00Z",
    "copy_participants": true,
    "copy_reminders": true
}
```

---

### 11. Export Events

**Endpoint:** `GET /api/events.php?action=export`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| format | string | Yes | Export format (ics) |
| start | string | Yes | Start date |
| end | string | Yes | End date |
| calendar_id | integer | No | Filter by calendar |

**Response:** Downloads .ics file

---

### 12. Import Events

**Endpoint:** `POST /api/events.php?action=import`

**Request Body:**
```json
{
    "ics_data": "BEGIN:VCALENDAR\n..."
}
```

Or use multipart form data with file upload:
```
POST /api/events.php?action=import
Content-Type: multipart/form-data

ics_file: [file]
```

**Response:**
```json
{
    "success": true,
    "data": {
        "imported": [1, 2, 3],
        "errors": [],
        "total": 3
    },
    "message": "Import completed. 3 events imported, 0 errors"
}
```

---

### 13. Reschedule Event

**Endpoint:** `POST /api/events.php?action=reschedule&id={id}`

**Request Body:**
```json
{
    "start": "2024-02-01T10:00:00Z",
    "end": "2024-02-01T11:00:00Z",
    "force": false // Force reschedule despite conflicts
}
```

---

## Recurrence Rules (RRULE)

The API supports RFC 5545 compliant recurrence rules:

### Examples:
- Daily: `FREQ=DAILY`
- Weekly on Mon, Wed, Fri: `FREQ=WEEKLY;BYDAY=MO,WE,FR`
- Monthly on 15th: `FREQ=MONTHLY;BYMONTHDAY=15`
- Yearly: `FREQ=YEARLY`
- Every 2 weeks: `FREQ=WEEKLY;INTERVAL=2`
- Daily for 10 occurrences: `FREQ=DAILY;COUNT=10`
- Weekly until date: `FREQ=WEEKLY;UNTIL=20241231T235959Z`

### Supported Parameters:
- `FREQ` - Frequency (DAILY, WEEKLY, MONTHLY, YEARLY)
- `INTERVAL` - Interval between occurrences
- `COUNT` - Number of occurrences
- `UNTIL` - End date for recurrence
- `BYDAY` - Days of week (MO,TU,WE,TH,FR,SA,SU)
- `BYMONTHDAY` - Days of month (1-31)
- `BYMONTH` - Months (1-12)

---

## Categories

Supported event categories:
- `general` - General event
- `meeting` - Meeting
- `task` - Task/deadline
- `reminder` - Reminder
- `review` - Review/evaluation
- `holiday` - Holiday/vacation
- `other` - Other

---

## Visibility Levels

- `private` - Only visible to creator and invited participants
- `public` - Visible to all users in tenant
- `team` - Visible to team members

---

## Participant Response Status

- `pending` - Not yet responded
- `accepted` - Accepted invitation
- `declined` - Declined invitation
- `tentative` - Maybe attending

---

## Error Handling

### Conflict Detection (409)
```json
{
    "success": false,
    "data": {
        "conflicts": [
            {
                "event_id": 123,
                "title": "Conflicting Event",
                "start": "2024-01-15T10:00:00Z",
                "end": "2024-01-15T11:00:00Z"
            }
        ]
    },
    "message": "Scheduling conflicts detected"
}
```

### Validation Errors (400)
```json
{
    "success": false,
    "data": null,
    "message": "Invalid date format. Use ISO 8601 format"
}
```

### Permission Errors (403)
```json
{
    "success": false,
    "data": null,
    "message": "Insufficient permissions to modify event"
}
```

---

## Rate Limiting

- Invitation sending: 100 per hour
- Event creation: 500 per day
- API calls: 1000 per hour

---

## Webhooks

The API can trigger webhooks for:
- Event created
- Event updated
- Event cancelled
- Invitation response received
- Reminder sent

Configure webhooks in tenant settings.

---

## Testing

Use the provided test interface at `/api/test_events_api.html` to test all endpoints interactively.

---

## Migration

Run the SQL migration to create necessary tables:
```sql
mysql -u username -p database_name < /api/migrations/create_events_tables.sql
```

---

## Support

For API support and questions:
- Documentation: `/api/docs/events_api_documentation.md`
- Test Interface: `/api/test_events_api.html`
- Contact: api-support@collaboranexio.com