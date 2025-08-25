# Organization Groups Overview

This document provides a comprehensive overview of the Organization Group functionality in the booking system, covering both the legacy PHP implementation and modern React/TypeScript components.

## Table of Contents
- [Database Schema](#database-schema)
- [System Relationships](#system-relationships)
- [Legacy PHP System](#legacy-php-system)
- [Modern React/TypeScript System](#modern-reacttypescript-system)
- [Key Features](#key-features)
- [API Endpoints](#api-endpoints)
- [User Interface Components](#user-interface-components)
- [Access Control](#access-control)
- [Development Status](#development-status)

## Database Schema

### bb_group Table
The main table storing organization group data.

| Column | Type | Description |
|--------|------|-------------|
| `id` | int (PK, auto-increment) | Unique group identifier |
| `name` | varchar(150) | Group name (required) |
| `organization_id` | int | Foreign key to bb_organization |
| `parent_id` | int (nullable) | Parent group ID for hierarchical structure |
| `description` | text (nullable) | Group description |
| `activity_id` | int (nullable) | Foreign key to bb_activity |
| `shortname` | varchar(11) (nullable) | Abbreviated group name |
| `active` | int (default 1) | Active status (1=active, 0=inactive) |
| `show_in_portal` | int (default 0) | Portal visibility (1=visible, 0=hidden) |

## System Relationships

Groups serve as a central organizational unit with multiple relationships throughout the booking system:

### Primary Database Relationships

#### 1. Bookings → Groups (Most Important)
- **Foreign Key**: `bb_booking.group_id` → `bb_group.id`
- **Usage**: Every booking is associated with a specific group
- **Impact**: All reservations are tracked by group for organizational reporting
- **Code Example**:
```sql
SELECT b.*, g.name as group_name, g.shortname as group_shortname
FROM bb_booking b
JOIN bb_group g ON b.group_id = g.id
WHERE b.from_ > '2024-01-01' AND b.to_ < '2024-12-31'
```

#### 2. Articles → Groups
- **Foreign Key**: `bb_article_mapping.group_id` → `bb_group.id`
- **Usage**: Articles/items can be restricted to specific groups
- **Impact**: Controls which groups can access/order specific resources
- **Example**: Training equipment only available to certain sports groups

#### 3. Group Contacts
- **Foreign Key**: `bb_group_contact.group_id` → `bb_group.id`
- **Usage**: Each group can have up to 2 contact persons
- **Impact**: Contact management for group communications and notifications
- **Enforcement**: `trim_contacts()` method limits to 2 contacts maximum

#### 4. Hierarchical Groups (Self-Referencing)
- **Foreign Key**: `bb_group.parent_id` → `bb_group.id`
- **Usage**: Groups can have parent groups within the same organization
- **Impact**: Creates organizational hierarchy for complex structures
- **Validation**: Prevents circular parent-child relationships

#### 5. Organization Integration
- **Foreign Key**: `bb_group.organization_id` → `bb_organization.id`
- **Usage**: Every group belongs to an organization
- **Impact**: Groups are managed within organizational boundaries

#### 6. Activity Integration
- **Foreign Key**: `bb_group.activity_id` → `bb_activity.id`
- **Usage**: Groups can be associated with specific activities
- **Impact**: Activity-based filtering and categorization

### Code Usage Patterns

#### Booking Management
```php
// File: class.sobooking.inc.php
// Get organization through group for validation
$sql = "SELECT organization_id FROM bb_group WHERE id = ($group_id)";

// Fetch bookings with group information
$sql = "SELECT bb_booking.*, bb_group.name as group_name 
        FROM bb_booking 
        INNER JOIN bb_group ON (bb_group.id = bb_booking.group_id)";
```

#### Email Notifications
```php
// File: class.async_task_send_reminder.inc.php
// Send booking reminders to group contacts
$sql = "SELECT DISTINCT name, email 
        FROM bb_group_contact 
        WHERE trim(email) <> '' AND group_id = $booking_group_id";
```

#### Organization Group Lookup
```php
// File: class.sobooking.inc.php
// Find all group contacts for an organization
$sql = "SELECT bb_group_contact.* 
        FROM bb_group, bb_group_contact 
        WHERE bb_group.id = bb_group_contact.group_id 
        AND bb_group.active = 1 
        AND bb_group.organization_id = $organization_id";
```

### Application Integration

While `bb_application` doesn't have a direct `group_id` field, groups connect through:
- **Organization Context**: Applications → Organizations → Groups
- **Booking Creation**: Applications can lead to bookings assigned to groups
- **User Permissions**: Application access validated through group membership
- **Workflow**: Application approval → Booking creation → Group assignment

### Event Integration

Events connect to groups indirectly through:
- **Organization Context**: Events belong to organizations that have groups
- **Activity Relationships**: Both events and groups can be linked to activities
- **Resource Sharing**: Events and group bookings may compete for same resources

### bb_group_contact Table
Stores contact information for groups (up to 2 contacts per group).

| Column | Type | Description |
|--------|------|-------------|
| `id` | int (PK, auto-increment) | Unique contact identifier |
| `group_id` | int | Foreign key to bb_group |
| `name` | varchar(150) | Contact person name |
| `phone` | varchar(50) | Contact phone number |
| `email` | varchar(255) | Contact email address |

## Legacy PHP System

### Backend Admin Module (booking)

#### Class Structure
- **UI Layer**: `class.uigroup.inc.php` - User interface and form handling
- **Business Logic**: `class.bogroup.inc.php` - Business rules and tree operations
- **Data Access**: `class.sogroup.inc.php` - Database operations and queries

#### Key Features

##### Group Management
- **List Groups**: View all groups with organization information
  - URL: `/index.php?menuaction=booking.uigroup.index`
  - Template: `group.xsl`
  - Features: Sortable datatable, organization filtering
  
- **Group Details**: View individual group information
  - URL: `/index.php?menuaction=booking.uigroup.show&id={id}`
  - Shows: Group details, hierarchical path, contact information
  
- **Create/Edit Groups**: Form-based group management
  - URL: `/index.php?menuaction=booking.uigroup.edit&id={id}`
  - Template: `group_edit.xsl`
  - Features: Parent selection, contact management, activity assignment

##### Hierarchical Structure
- **Tree Building**: Recursive tree structure for parent/child relationships
- **Path Generation**: Full hierarchical path from root to current group
- **Circular Reference Prevention**: Validates parent selection to prevent loops
- **Child Group Management**: List and manage child groups

##### Contact Management
- **Multiple Contacts**: Up to 2 contact persons per group
- **Contact Information**: Name, phone, email for each contact
- **Validation**: Trims contacts to maximum of 2 per group

#### Templates (XSL)
- `group.xsl` - Group listing and details view
- `group_edit.xsl` - Group creation and editing form
- `organization.xsl` - Includes group tables in organization view

### Frontend Public Module (bookingfrontend)

#### Features
- **Public Group View**: Organization admins can view group details
  - URL: `/bookingfrontend/index.php?menuaction=bookingfrontend.uigroup.show&id={id}`
  - Access: Organization admins only
  
- **Group Editing**: Organization admins can edit their groups
  - URL: `/bookingfrontend/index.php?menuaction=bookingfrontend.uigroup.edit&id={id}`
  - Access: Organization admins only

## Modern React/TypeScript System

### Type Definitions
Located in `src/modules/bookingfrontend/client/src/service/types/api/organization.types.ts`

```typescript
interface IOrganizationGroup {
    id: number;
    name: string;
    organization_id: number;
    parent_id?: number | null;
    description?: string | null;
    activity_id?: number | null;
    shortname?: string | null;
    active: number;
    show_in_portal: number;
}

interface IShortOrganizationGroup extends Pick<IOrganizationGroup, 
    'id' | 'name' | 'organization_id' | 'parent_id' | 'activity_id' | 'shortname' | 'active' | 'show_in_portal'> {}
```

### API Integration

#### React Query Hooks
Located in `src/modules/bookingfrontend/client/src/service/hooks/organization.ts`

```typescript
// Fetch organization groups
export const useOrganizationGroups = (id: number) => {
    return useQuery({
        queryKey: ['organization', id, 'groups'],
        queryFn: () => organizationApi.getOrganizationGroups(id),
        enabled: !!id,
    });
};
```

#### API Functions
Located in `src/modules/bookingfrontend/client/src/service/api/building.ts`

```typescript
// Get organization groups
const getOrganizationGroups = async (id: number): Promise<IOrganizationGroup[]> => {
    const response = await apiRequest(`/organizations/${id}/groups`);
    return response.data;
};
```

### UI Components

#### Organization Groups Display
Located in `src/modules/bookingfrontend/client/src/components/organization-page/groups/organization-groups-content.tsx`

**Features**:
- Accordion-style group display
- Shows group name and shortname
- Loading and error states
- Empty state handling

#### Management Page (Placeholder)
Located in `src/modules/bookingfrontend/client/src/app/[lang]/(public)/organization/[id]/groups/page.tsx`

**Planned Features**:
- Complete group CRUD interface
- Team leader assignment (up to 2 per group)
- Group statistics and member management
- Hierarchical group structure display

## Key Features

### Hierarchical Structure
- **Parent/Child Relationships**: Groups can have parent groups within the same organization
- **Tree Navigation**: Full path display from root to current group
- **Circular Reference Prevention**: System prevents creating circular parent-child relationships
- **Recursive Operations**: Tree traversal and child group fetching

### Contact Management
- **Multiple Contacts**: Up to 2 contact persons per group
- **Contact Details**: Name, phone number, and email address
- **Validation**: Automatic trimming to enforce 2-contact limit
- **No Tiers/Roles**: All contacts are treated equally (no primary/secondary designation)
- **Notification System**: Used for sending booking reminders and group communications

### Activity Integration
- **Activity Assignment**: Groups can be linked to specific activities
- **Activity-based Filtering**: Groups can be filtered by associated activities

### Portal Visibility
- **Public Display Control**: Groups can be shown or hidden in public portal
- **Visibility Toggle**: `show_in_portal` flag controls public visibility

### Organization Integration
- **Organization Ownership**: All groups belong to a specific organization
- **Admin Management**: Organization admins can manage their groups
- **Inline Management**: Groups can be managed directly from organization pages

## API Endpoints

### Legacy PHP Endpoints
- `GET /index.php?menuaction=booking.uigroup.index` - List groups
- `GET /index.php?menuaction=booking.uigroup.show&id={id}` - Get group details
- `POST /index.php?menuaction=booking.uigroup.edit` - Create/update group
- `GET /index.php?menuaction=booking.uigroup.fetch_groups&organization_id={id}` - Get organization groups

### Modern REST Endpoints
- `GET /organizations/{id}/groups` - Get organization groups
- `POST /organizations/{id}/groups` - Create new group (requires authentication)
- `PUT /organizations/{id}/groups/{groupId}` - Update group (requires authentication)  
- `DELETE /organizations/{id}/groups/{groupId}` - Delete group (requires authentication)

## User Interface Components

### Legacy PHP Templates
- **Group Listing**: Sortable datatable with organization information
- **Group Details**: Detailed view with hierarchical path
- **Group Form**: Create/edit form with parent selection and contact management
- **Organization Integration**: Inline group tables in organization views

### Modern React Components
- **OrganizationGroupsContent**: Accordion display of organization groups
- **Group Management Page**: Comprehensive CRUD interface (in development)
- **Group Statistics**: Member counts and activity tracking (planned)

## Access Control

### Backend Admin
- **Full Access**: System administrators have complete group management access
- **Organization Filtering**: Groups are filtered by organization in listings
- **Menu Access**: Groups accessible through Booking → Organizations → Groups

### Frontend Public
- **Organization Admins**: Can view and edit groups within their organization
- **Authentication Required**: SSN-based authentication for organization access
- **Permission Validation**: `UserHelper::is_organization_admin()` validates access

### Access Patterns
- **Hierarchical Permissions**: Organization admins can manage all groups within their organization
- **Group Leader Access**: Group contacts can manage their specific group (2 contacts max)
- **Public Visibility**: Groups with `show_in_portal=1` are publicly visible

## Development Status

### Completed Features ✅
- **Database Schema**: Complete with relationships and constraints
- **Legacy PHP System**: Full CRUD operations in backend admin
- **Business Logic**: Hierarchical structure, validation, contact management
- **Modern API Endpoints**: Full CRUD operations with access control
- **TypeScript Types**: Complete type definitions
- **Basic React Components**: Display components implemented
- **Access Control**: Organization admin authentication and authorization

### In Development 🔄
- **React Management Interface**: Complete group creation/editing forms
- **Team Leader Assignment**: UI for managing group contacts/leaders
- **Frontend Integration**: Connect new API endpoints to React components

### Planned Features 📋
- **Group Statistics**: Member counts, booking statistics, activity tracking
- **Bulk Operations**: Import/export group data
- **Enhanced Permissions**: Fine-grained access control
- **Mobile Optimization**: Responsive design for mobile devices
- **Search and Filtering**: Advanced group search capabilities
- **Notification System**: Email notifications for group updates

## File Locations

### Legacy PHP Files
```
src/modules/booking/inc/
├── class.uigroup.inc.php          # UI layer
├── class.bogroup.inc.php          # Business logic
└── class.sogroup.inc.php          # Data access

src/modules/booking/templates/base/
├── group.xsl                      # Group listing/details template
└── group_edit.xsl                 # Group editing template

src/modules/bookingfrontend/inc/
├── class.uigroup.inc.php          # Frontend UI layer
└── class.uiorganization.inc.php   # Organization integration
```

### Modern React/TypeScript Files
```
src/modules/bookingfrontend/client/src/
├── service/types/api/organization.types.ts           # Type definitions
├── service/hooks/organization.ts                     # React Query hooks
├── service/api/building.ts                          # API functions
├── components/organization-page/groups/              # Group components
└── app/[lang]/(public)/organization/[id]/groups/     # Management pages
```

### API Routes
```
src/modules/bookingfrontend/routes/
└── Routes.php                     # REST API route definitions
```

## Usage Examples

### Fetching Organization Groups (React)
```typescript
import { useOrganizationGroups } from '@/service/hooks/organization';

function OrganizationPage({ organizationId }: { organizationId: number }) {
    const { data: groups, isLoading, error } = useOrganizationGroups(organizationId);
    
    if (isLoading) return <div>Loading groups...</div>;
    if (error) return <div>Error loading groups</div>;
    
    return (
        <div>
            {groups?.map(group => (
                <div key={group.id}>
                    <h3>{group.name}</h3>
                    {group.shortname && <span>({group.shortname})</span>}
                    {group.description && <p>{group.description}</p>}
                </div>
            ))}
        </div>
    );
}
```

### Creating a Group (Legacy PHP)
```php
// Access via: /index.php?menuaction=booking.uigroup.edit&organization_id=123
$group_data = array(
    'name' => 'Youth Soccer Team',
    'organization_id' => 123,
    'parent_id' => null,
    'description' => 'Soccer team for ages 12-16',
    'shortname' => 'YouthSoccer',
    'active' => 1,
    'show_in_portal' => 1
);
```

### Group-Booking Relationship Usage
```php
// Find all bookings for a specific group
$sql = "SELECT b.*, r.name as resource_name 
        FROM bb_booking b
        JOIN bb_group g ON b.group_id = g.id
        JOIN bb_resource r ON b.resource_id = r.id
        WHERE g.id = $group_id 
        AND b.from_ >= NOW()
        ORDER BY b.from_ ASC";

// Get group contact emails for booking notifications
$sql = "SELECT DISTINCT gc.email, gc.name
        FROM bb_group_contact gc
        WHERE gc.group_id = $group_id 
        AND TRIM(gc.email) <> ''";
```

This overview provides a complete picture of the Organization Group system, from database structure to user interfaces, covering both legacy and modern implementations.