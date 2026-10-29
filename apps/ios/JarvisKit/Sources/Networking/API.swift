import Foundation

/// The endpoints of the API v1 the app foundation uses (`docs/api/v1.yaml`).
public enum API {
    // MARK: - Auth

    public static func login(email: String, password: String, deviceName: String) -> Endpoint<TokenResponse> {
        Endpoint(
            method: .post,
            path: "/auth/login",
            json: ["email": email, "password": password, "device_name": deviceName],
            isAuthenticated: false
        )
    }

    public static func google(idToken: String, deviceName: String) -> Endpoint<TokenResponse> {
        Endpoint(
            method: .post,
            path: "/auth/google",
            json: ["id_token": idToken, "device_name": deviceName],
            isAuthenticated: false
        )
    }

    public static func logout() -> Endpoint<EmptyResponse> {
        Endpoint(method: .post, path: "/auth/logout")
    }

    // MARK: - Profile

    public static func me() -> Endpoint<DataEnvelope<User>> {
        Endpoint(method: .get, path: "/me")
    }

    /// The user's own color for a workspace, or making it their default.
    public static func updateWorkspacePreference(id: String, _ input: WorkspacePreferenceInput) -> Endpoint<DataEnvelope<UserWorkspace>> {
        Endpoint(method: .patch, path: "/me/workspaces/\(id)", json: input)
    }

    /// The colors a workspace may have.
    public static func workspaceColors() -> Endpoint<DataEnvelope<[WorkspaceColorOption]>> {
        Endpoint(method: .get, path: "/workspace-colors")
    }

    public static func updatePreferences(locale: String? = nil, timezone: String? = nil) -> Endpoint<DataEnvelope<User>> {
        var preferences: [String: String] = [:]
        preferences["locale"] = locale
        preferences["timezone"] = timezone

        return Endpoint(method: .patch, path: "/me/preferences", json: preferences)
    }

    // MARK: - Devices

    public static func registerDevice(_ registration: DeviceRegistration) -> Endpoint<DataEnvelope<Device>> {
        Endpoint(method: .post, path: "/devices", json: registration)
    }

    // MARK: - Notifications

    public static func notifications(page: Int = 1, perPage: Int = 20) -> Endpoint<NotificationPage> {
        Endpoint(
            method: .get,
            path: "/notifications",
            query: [URLQueryItem(name: "page", value: String(page)), URLQueryItem(name: "per_page", value: String(perPage))]
        )
    }

    public static func markNotificationRead(id: String) -> Endpoint<NotificationsMarked> {
        Endpoint(method: .post, path: "/notifications/\(id)/read")
    }

    public static func markAllNotificationsRead() -> Endpoint<NotificationsMarked> {
        Endpoint(method: .post, path: "/notifications/read-all")
    }

    // MARK: - Realtime

    public static func broadcastingAuth(socketId: String, channelName: String) -> Endpoint<BroadcastAuthorization> {
        Endpoint(
            method: .post,
            path: "/broadcasting/auth",
            json: ["socket_id": socketId, "channel_name": channelName]
        )
    }

    // MARK: - Reminders

    /// The occurrences of a filter; with `mine`, only the user's own.
    public static func reminderOccurrences(filter: ReminderFilter, mine: Bool = false) -> Endpoint<ReminderOccurrencePage> {
        var query = [URLQueryItem(name: "filter", value: filter.rawValue)]

        if mine {
            query.append(URLQueryItem(name: "mine", value: "1"))
        }

        return Endpoint(method: .get, path: "/reminders", query: query)
    }

    /// The open occurrences by day; with `mine`, only the user's own.
    public static func reminderAgenda(mine: Bool = false) -> Endpoint<ReminderAgendaPage> {
        Endpoint(method: .get, path: "/reminders/agenda", query: mine ? [URLQueryItem(name: "mine", value: "1")] : [])
    }

    /// Everything scheduled starting in [`from`, `to`) — at most 62 days
    /// apart — as the web's calendar; with `mine`, only the user's own
    /// reminders and events; `hidden` sources left out.
    public static func agenda(from: Date, to: Date, mine: Bool = false, hidden: [CalendarSource] = []) -> Endpoint<CalendarPage> {
        let formatter = ISO8601DateFormatter()
        var query = [
            URLQueryItem(name: "from", value: formatter.string(from: from)),
            URLQueryItem(name: "to", value: formatter.string(from: to)),
        ]

        if mine {
            query.append(URLQueryItem(name: "mine", value: "1"))
        }

        query += hidden.map { URLQueryItem(name: "hide[]", value: $0.rawValue) }

        return Endpoint(method: .get, path: "/agenda", query: query)
    }

    /// One reminder or event, as the calendar names it by id.
    public static func reminder(id: String) -> Endpoint<ReminderPage> {
        Endpoint(method: .get, path: "/reminders/\(id)")
    }

    public static func createReminder(_ input: ReminderInput) -> Endpoint<DataEnvelope<Reminder>> {
        Endpoint(method: .post, path: "/reminders", json: input)
    }

    /// Replaces the whole reminder, as the web form does.
    public static func updateReminder(id: String, _ input: ReminderInput) -> Endpoint<DataEnvelope<Reminder>> {
        Endpoint(method: .patch, path: "/reminders/\(id)", json: input)
    }

    public static func deleteReminder(id: String) -> Endpoint<EmptyResponse> {
        Endpoint(method: .delete, path: "/reminders/\(id)")
    }

    public static func completeOccurrence(id: String) -> Endpoint<DataEnvelope<ReminderOccurrence>> {
        Endpoint(method: .post, path: "/reminder-occurrences/\(id)/complete")
    }

    public static func reopenOccurrence(id: String) -> Endpoint<DataEnvelope<ReminderOccurrence>> {
        Endpoint(method: .post, path: "/reminder-occurrences/\(id)/reopen")
    }

    // MARK: - Shopping

    public static func shoppingLists(filter: ShoppingListFilter = .active) -> Endpoint<DataEnvelope<[ShoppingList]>> {
        Endpoint(method: .get, path: "/shopping/lists", query: [URLQueryItem(name: "filter", value: filter.rawValue)])
    }

    public static func shoppingList(id: String) -> Endpoint<DataEnvelope<ShoppingList>> {
        Endpoint(method: .get, path: "/shopping/lists/\(id)")
    }

    /// In `workspace` (a friendly id); without one, in the user's default.
    public static func createShoppingList(name: String, workspace: String? = nil) -> Endpoint<DataEnvelope<ShoppingList>> {
        var body = ["name": name]
        body["workspace"] = workspace

        return Endpoint(method: .post, path: "/shopping/lists", json: body)
    }

    /// Renames the list and, with `workspace`, moves it there.
    public static func renameShoppingList(id: String, name: String, workspace: String? = nil) -> Endpoint<DataEnvelope<ShoppingList>> {
        var body = ["name": name]
        body["workspace"] = workspace

        return Endpoint(method: .patch, path: "/shopping/lists/\(id)", json: body)
    }

    public static func deleteShoppingList(id: String) -> Endpoint<EmptyResponse> {
        Endpoint(method: .delete, path: "/shopping/lists/\(id)")
    }

    /// A list of a recurring one: closed now, by hand; the schedule makes the next.
    public static func completeShoppingList(id: String) -> Endpoint<DataEnvelope<ShoppingList>> {
        Endpoint(method: .post, path: "/shopping/lists/\(id)/complete")
    }

    public static func shoppingSchedules() -> Endpoint<DataEnvelope<[ShoppingListSchedule]>> {
        Endpoint(method: .get, path: "/shopping/schedules")
    }

    public static func shoppingSchedule(id: String) -> Endpoint<DataEnvelope<ShoppingListSchedule>> {
        Endpoint(method: .get, path: "/shopping/schedules/\(id)")
    }

    /// Makes its first list too.
    public static func createShoppingSchedule(_ input: ShoppingListScheduleInput) -> Endpoint<DataEnvelope<ShoppingListSchedule>> {
        Endpoint(method: .post, path: "/shopping/schedules", json: input)
    }

    public static func updateShoppingSchedule(id: String, _ input: ShoppingListScheduleInput) -> Endpoint<DataEnvelope<ShoppingListSchedule>> {
        Endpoint(method: .patch, path: "/shopping/schedules/\(id)", json: input)
    }

    /// No list is made anymore; the ones it made stay.
    public static func deleteShoppingSchedule(id: String) -> Endpoint<EmptyResponse> {
        Endpoint(method: .delete, path: "/shopping/schedules/\(id)")
    }

    public static func archiveShoppingList(id: String) -> Endpoint<DataEnvelope<ShoppingList>> {
        Endpoint(method: .post, path: "/shopping/lists/\(id)/archive")
    }

    public static func unarchiveShoppingList(id: String) -> Endpoint<DataEnvelope<ShoppingList>> {
        Endpoint(method: .post, path: "/shopping/lists/\(id)/unarchive")
    }

    /// Makes the list the household's default (`POST`) or unsets it (`DELETE`).
    public static func setDefaultShoppingList(id: String, isDefault: Bool) -> Endpoint<DataEnvelope<ShoppingList>> {
        Endpoint(method: isDefault ? .post : .delete, path: "/shopping/lists/\(id)/default")
    }

    public static func clearCheckedItems(listId: String) -> Endpoint<ClearedCheckedItems> {
        Endpoint(method: .post, path: "/shopping/lists/\(listId)/clear-checked")
    }

    /// The unchecked items of the list in a new order (drag and drop).
    public static func reorderShoppingItems(listId: String, itemIds: [String]) -> Endpoint<DataEnvelope<ShoppingList>> {
        Endpoint(method: .post, path: "/shopping/lists/\(listId)/reorder", json: ["items": itemIds])
    }

    public static func addShoppingItem(listId: String, name: String, quantityNote: String?) -> Endpoint<DataEnvelope<ShoppingListItem>> {
        Endpoint(method: .post, path: "/shopping/lists/\(listId)/items", json: ShoppingItemInput(name: name, quantityNote: quantityNote))
    }

    public static func updateShoppingItem(id: String, name: String, quantityNote: String?) -> Endpoint<DataEnvelope<ShoppingListItem>> {
        Endpoint(method: .patch, path: "/shopping/items/\(id)", json: ShoppingItemInput(name: name, quantityNote: quantityNote))
    }

    public static func deleteShoppingItem(id: String) -> Endpoint<EmptyResponse> {
        Endpoint(method: .delete, path: "/shopping/items/\(id)")
    }

    /// Checks or unchecks an item, last write wins (US-8.2): `checked` is the
    /// state to end in and `clientUpdatedAt` the moment of the tap on the device.
    public static func toggleShoppingItem(id: String, checked: Bool, clientUpdatedAt: Date?) -> Endpoint<DataEnvelope<ShoppingListItem>> {
        Endpoint(method: .post, path: "/shopping/items/\(id)/toggle", json: ShoppingItemToggle(checked: checked, clientUpdatedAt: clientUpdatedAt))
    }

    // MARK: - WhatsApp

    public static func whatsappAccounts() -> Endpoint<DataEnvelope<[WhatsappAccount]>> {
        Endpoint(method: .get, path: "/whatsapp/accounts")
    }

    /// The account's contacts and groups, by name or number.
    public static func whatsappTargets(accountId: String, search: String? = nil, page: Int = 1, perPage: Int = 30) -> Endpoint<Page<WhatsappTarget>> {
        var query = [URLQueryItem(name: "page", value: String(page)), URLQueryItem(name: "per_page", value: String(perPage))]

        if let search, !search.isEmpty {
            query.append(URLQueryItem(name: "search", value: search))
        }

        return Endpoint(method: .get, path: "/whatsapp/accounts/\(accountId)/targets", query: query)
    }

    public static func whatsappMessages() -> Endpoint<DataEnvelope<[WhatsappMessage]>> {
        Endpoint(method: .get, path: "/whatsapp/messages")
    }

    public static func scheduleWhatsappMessage(_ input: WhatsappMessageInput) -> Endpoint<DataEnvelope<WhatsappMessage>> {
        Endpoint(method: .post, path: "/whatsapp/messages", json: input)
    }

    /// Replaces the whole message; a cancelled one cannot be edited.
    public static func updateWhatsappMessage(id: String, _ input: WhatsappMessageInput) -> Endpoint<DataEnvelope<WhatsappMessage>> {
        Endpoint(method: .patch, path: "/whatsapp/messages/\(id)", json: input)
    }

    public static func deleteWhatsappMessage(id: String) -> Endpoint<EmptyResponse> {
        Endpoint(method: .delete, path: "/whatsapp/messages/\(id)")
    }

    public static func cancelWhatsappMessage(id: String) -> Endpoint<DataEnvelope<WhatsappMessage>> {
        Endpoint(method: .post, path: "/whatsapp/messages/\(id)/cancel")
    }

    public static func pauseWhatsappMessage(id: String) -> Endpoint<DataEnvelope<WhatsappMessage>> {
        Endpoint(method: .post, path: "/whatsapp/messages/\(id)/pause")
    }

    public static func resumeWhatsappMessage(id: String) -> Endpoint<DataEnvelope<WhatsappMessage>> {
        Endpoint(method: .post, path: "/whatsapp/messages/\(id)/resume")
    }

    /// Sends right away, one `manual` delivery per target (US-7.5); the schedule is not sent.
    public static func sendWhatsappNow(_ input: WhatsappMessageInput) -> Endpoint<DataEnvelope<[WhatsappDelivery]>> {
        Endpoint(
            method: .post,
            path: "/whatsapp/send-now",
            json: WhatsappMessageInput(account: input.account, targets: input.targets, numbers: input.numbers, body: input.body, recurrence: nil)
        )
    }

    /// The history of the deliveries, newest first.
    public static func whatsappDeliveries(page: Int = 1, perPage: Int = 20) -> Endpoint<Page<WhatsappDelivery>> {
        Endpoint(
            method: .get,
            path: "/whatsapp/deliveries",
            query: [URLQueryItem(name: "page", value: String(page)), URLQueryItem(name: "per_page", value: String(perPage))]
        )
    }

    // MARK: - Admin (super admin only)

    public static func adminModules() -> Endpoint<DataEnvelope<[AdminModule]>> {
        Endpoint(method: .get, path: "/admin/modules")
    }

    public static func adminWorkspaces() -> Endpoint<DataEnvelope<[AdminWorkspace]>> {
        Endpoint(method: .get, path: "/admin/workspaces")
    }

    public static func adminWorkspace(id: String) -> Endpoint<DataEnvelope<AdminWorkspaceDetail>> {
        Endpoint(method: .get, path: "/admin/workspaces/\(id)")
    }

    public static func createAdminWorkspace(_ input: AdminWorkspaceInput) -> Endpoint<DataEnvelope<AdminWorkspace>> {
        Endpoint(method: .post, path: "/admin/workspaces", json: input)
    }

    public static func updateAdminWorkspace(id: String, _ input: AdminWorkspaceInput) -> Endpoint<DataEnvelope<AdminWorkspaceDetail>> {
        Endpoint(method: .patch, path: "/admin/workspaces/\(id)", json: input)
    }

    /// Refused (422 on `workspace`) while the workspace has data.
    public static func deleteAdminWorkspace(id: String) -> Endpoint<EmptyResponse> {
        Endpoint(method: .delete, path: "/admin/workspaces/\(id)")
    }

    /// Adds an existing user; `input.user` is their friendly id.
    public static func addAdminWorkspaceMember(workspaceId: String, _ input: AdminMemberInput) -> Endpoint<DataEnvelope<AdminWorkspaceMember>> {
        Endpoint(method: .post, path: "/admin/workspaces/\(workspaceId)/members", json: input)
    }

    public static func updateAdminWorkspaceMember(workspaceId: String, userId: String, _ input: AdminMemberInput) -> Endpoint<DataEnvelope<AdminWorkspaceMember>> {
        Endpoint(
            method: .patch,
            path: "/admin/workspaces/\(workspaceId)/members/\(userId)",
            json: AdminMemberInput(modules: input.modules, color: input.color, isDefault: input.isDefault)
        )
    }

    public static func removeAdminWorkspaceMember(workspaceId: String, userId: String) -> Endpoint<EmptyResponse> {
        Endpoint(method: .delete, path: "/admin/workspaces/\(workspaceId)/members/\(userId)")
    }

    public static func adminUsers() -> Endpoint<DataEnvelope<[AdminUser]>> {
        Endpoint(method: .get, path: "/admin/users")
    }

    /// Deactivates (signs out everywhere, no more access) or reactivates a user.
    public static func setAdminUserActive(id: String, isActive: Bool) -> Endpoint<DataEnvelope<AdminUser>> {
        Endpoint(method: .post, path: "/admin/users/\(id)/\(isActive ? "reactivate" : "deactivate")")
    }

    public static func adminInvitations() -> Endpoint<DataEnvelope<[AdminInvitation]>> {
        Endpoint(method: .get, path: "/admin/invitations")
    }

    public static func createAdminInvitation(_ input: AdminInvitationInput) -> Endpoint<DataEnvelope<AdminInvitation>> {
        Endpoint(method: .post, path: "/admin/invitations", json: input)
    }

    public static func resendAdminInvitation(id: String) -> Endpoint<DataEnvelope<AdminInvitation>> {
        Endpoint(method: .post, path: "/admin/invitations/\(id)/resend")
    }

    public static func revokeAdminInvitation(id: String) -> Endpoint<DataEnvelope<AdminInvitation>> {
        Endpoint(method: .post, path: "/admin/invitations/\(id)/revoke")
    }

    // MARK: - Voice

    /// The remote interpreter (US-10.2): the server's AI maps the spoken text to an action and runs it.
    public static func interpretVoiceCommand(text: String) -> Endpoint<VoiceCommandResult> {
        Endpoint(method: .post, path: "/voice/commands", json: ["text": text])
    }

    /// Runs an action structured on the device (the local interpreter, the
    /// Siri intents) or answers a `needs_clarification` with the picked option.
    public static func runVoiceAction(_ action: VoiceAction) -> Endpoint<VoiceCommandResult> {
        Endpoint(method: .post, path: "/voice/actions", json: action)
    }

    /// Sends the WhatsApp message of a `needs_confirmation` answer (US-10.3).
    public static func confirmVoiceCommand(token: String) -> Endpoint<VoiceCommandResult> {
        Endpoint(method: .post, path: "/voice/commands/confirm", json: ["confirmation_token": token])
    }
}
