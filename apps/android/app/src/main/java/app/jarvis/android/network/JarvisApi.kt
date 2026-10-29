package app.jarvis.android.network

import kotlinx.serialization.json.JsonObject
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * The endpoints of the API v1 the app uses (`docs/api/v1.yaml`),
 * relative to `/api/v1/`. The sign-in calls carry [ApiClient.NO_AUTH_HEADER]:
 * they go without the token, and their 401 is not a lost session.
 */
interface JarvisApi {
    // Auth

    @POST("auth/login")
    @retrofit2.http.Headers(ApiClient.NO_AUTH_HEADER)
    suspend fun login(@Body body: LoginRequest): TokenResponse

    @POST("auth/google")
    @retrofit2.http.Headers(ApiClient.NO_AUTH_HEADER)
    suspend fun google(@Body body: GoogleLoginRequest): TokenResponse

    @POST("auth/logout")
    suspend fun logout()

    // Profile

    @GET("me")
    suspend fun me(): DataEnvelope<User>

    @PATCH("me/preferences")
    suspend fun updatePreferences(@Body body: PreferencesRequest): DataEnvelope<User>

    /** The user's own color for a workspace, or making it their default ([WorkspacePreferenceInput.body]). */
    @PATCH("me/workspaces/{id}")
    suspend fun updateWorkspacePreference(@Path("id") workspaceId: String, @Body body: JsonObject): DataEnvelope<UserWorkspace>

    /** The colors a workspace may have. */
    @GET("workspace-colors")
    suspend fun workspaceColors(): DataEnvelope<List<WorkspaceColorOption>>

    // Devices

    @POST("devices")
    suspend fun registerDevice(@Body body: DeviceRegistration): DataEnvelope<Device>

    // Notifications

    @GET("notifications")
    suspend fun notifications(
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 20,
    ): NotificationPage

    @POST("notifications/{id}/read")
    suspend fun markNotificationRead(@Path("id") id: String): NotificationsMarked

    @POST("notifications/read-all")
    suspend fun markAllNotificationsRead(): NotificationsMarked

    // Shopping

    @GET("shopping/lists")
    suspend fun shoppingLists(@Query("filter") filter: String = "active"): DataEnvelope<List<ShoppingList>>

    @GET("shopping/lists/{id}")
    suspend fun shoppingList(@Path("id") id: String): DataEnvelope<ShoppingList>

    @POST("shopping/lists")
    suspend fun createShoppingList(@Body body: ShoppingListInput): DataEnvelope<ShoppingList>

    /** Renames the list and, with [ShoppingListInput.workspace], moves it there. */
    @PATCH("shopping/lists/{id}")
    suspend fun renameShoppingList(@Path("id") id: String, @Body body: ShoppingListInput): DataEnvelope<ShoppingList>

    @DELETE("shopping/lists/{id}")
    suspend fun deleteShoppingList(@Path("id") id: String)

    /** A list of a recurring one: closed now, by hand; the schedule makes the next. */
    @POST("shopping/lists/{id}/complete")
    suspend fun completeShoppingList(@Path("id") id: String): DataEnvelope<ShoppingList>

    @GET("shopping/schedules")
    suspend fun shoppingSchedules(): DataEnvelope<List<ShoppingListSchedule>>

    @GET("shopping/schedules/{id}")
    suspend fun shoppingSchedule(@Path("id") id: String): DataEnvelope<ShoppingListSchedule>

    /** Makes its first list too. */
    @POST("shopping/schedules")
    suspend fun createShoppingSchedule(@Body body: ShoppingListScheduleInput): DataEnvelope<ShoppingListSchedule>

    @PATCH("shopping/schedules/{id}")
    suspend fun updateShoppingSchedule(@Path("id") id: String, @Body body: ShoppingListScheduleInput): DataEnvelope<ShoppingListSchedule>

    /** No list is made anymore; the ones it made stay. */
    @DELETE("shopping/schedules/{id}")
    suspend fun deleteShoppingSchedule(@Path("id") id: String)

    @POST("shopping/lists/{id}/archive")
    suspend fun archiveShoppingList(@Path("id") id: String): DataEnvelope<ShoppingList>

    @POST("shopping/lists/{id}/unarchive")
    suspend fun unarchiveShoppingList(@Path("id") id: String): DataEnvelope<ShoppingList>

    @POST("shopping/lists/{id}/default")
    suspend fun makeDefaultShoppingList(@Path("id") id: String): DataEnvelope<ShoppingList>

    @DELETE("shopping/lists/{id}/default")
    suspend fun unsetDefaultShoppingList(@Path("id") id: String): DataEnvelope<ShoppingList>

    @POST("shopping/lists/{id}/clear-checked")
    suspend fun clearCheckedItems(@Path("id") listId: String): ClearedCheckedItems

    @POST("shopping/lists/{id}/reorder")
    suspend fun reorderShoppingItems(@Path("id") listId: String, @Body body: ShoppingItemsOrder): DataEnvelope<ShoppingList>

    @POST("shopping/lists/{id}/items")
    suspend fun addShoppingItem(@Path("id") listId: String, @Body body: ShoppingItemInput): DataEnvelope<ShoppingListItem>

    @PATCH("shopping/items/{id}")
    suspend fun updateShoppingItem(@Path("id") id: String, @Body body: ShoppingItemInput): DataEnvelope<ShoppingListItem>

    @DELETE("shopping/items/{id}")
    suspend fun deleteShoppingItem(@Path("id") id: String)

    /** Checks or unchecks an item, last write wins (US-8.2). */
    @POST("shopping/items/{id}/toggle")
    suspend fun toggleShoppingItem(@Path("id") id: String, @Body body: ShoppingItemToggle): DataEnvelope<ShoppingListItem>

    // Reminders

    /** The occurrences of a filter; with [mine] (`1`), only the user's own. */
    @GET("reminders")
    suspend fun reminderOccurrences(@Query("filter") filter: String, @Query("mine") mine: Int? = null): ReminderOccurrencePage

    /** The open occurrences by day; with [mine] (`1`), only the user's own. */
    @GET("reminders/agenda")
    suspend fun reminderAgenda(@Query("mine") mine: Int? = null): ReminderAgendaPage

    /**
     * Everything scheduled starting in [`from`, `to`) — at most 62 days apart
     * — as the web's calendar; with `mine`, only the user's own reminders and
     * events; `hide` sources left out.
     */
    @GET("agenda")
    suspend fun agenda(
        @Query("from") from: String,
        @Query("to") to: String,
        @Query("mine") mine: Int? = null,
        @Query("hide[]") hide: List<String> = emptyList(),
    ): CalendarPage

    /** One reminder or event, as the calendar names it by id. */
    @GET("reminders/{id}")
    suspend fun reminder(@Path("id") id: String): ReminderPage

    @POST("reminders")
    suspend fun createReminder(@Body body: ReminderInput): DataEnvelope<Reminder>

    /** Replaces the whole reminder, as the web form does. */
    @PATCH("reminders/{id}")
    suspend fun updateReminder(@Path("id") id: String, @Body body: ReminderInput): DataEnvelope<Reminder>

    @DELETE("reminders/{id}")
    suspend fun deleteReminder(@Path("id") id: String)

    @POST("reminder-occurrences/{id}/complete")
    suspend fun completeOccurrence(@Path("id") id: String): DataEnvelope<ReminderOccurrence>

    @POST("reminder-occurrences/{id}/reopen")
    suspend fun reopenOccurrence(@Path("id") id: String): DataEnvelope<ReminderOccurrence>

    // WhatsApp

    @GET("whatsapp/accounts")
    suspend fun whatsappAccounts(): DataEnvelope<List<WhatsappAccount>>

    /** The account's contacts and groups, by name or number. */
    @GET("whatsapp/accounts/{id}/targets")
    suspend fun whatsappTargets(
        @Path("id") accountId: String,
        @Query("search") search: String? = null,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 30,
    ): Page<WhatsappTarget>

    @GET("whatsapp/messages")
    suspend fun whatsappMessages(): DataEnvelope<List<WhatsappMessage>>

    @POST("whatsapp/messages")
    suspend fun scheduleWhatsappMessage(@Body body: WhatsappMessageInput): DataEnvelope<WhatsappMessage>

    /** Replaces the whole message; a cancelled one cannot be edited. */
    @PATCH("whatsapp/messages/{id}")
    suspend fun updateWhatsappMessage(@Path("id") id: String, @Body body: WhatsappMessageInput): DataEnvelope<WhatsappMessage>

    @DELETE("whatsapp/messages/{id}")
    suspend fun deleteWhatsappMessage(@Path("id") id: String)

    @POST("whatsapp/messages/{id}/cancel")
    suspend fun cancelWhatsappMessage(@Path("id") id: String): DataEnvelope<WhatsappMessage>

    @POST("whatsapp/messages/{id}/pause")
    suspend fun pauseWhatsappMessage(@Path("id") id: String): DataEnvelope<WhatsappMessage>

    @POST("whatsapp/messages/{id}/resume")
    suspend fun resumeWhatsappMessage(@Path("id") id: String): DataEnvelope<WhatsappMessage>

    /** Sends right away, one `manual` delivery per target (US-7.5); the schedule is not sent. */
    @POST("whatsapp/send-now")
    suspend fun sendWhatsappNow(@Body body: WhatsappMessageInput): DataEnvelope<List<WhatsappDelivery>>

    /** The history of the deliveries, newest first. */
    @GET("whatsapp/deliveries")
    suspend fun whatsappDeliveries(@Query("page") page: Int = 1, @Query("per_page") perPage: Int = 20): Page<WhatsappDelivery>

    // Reply keyboard

    /** The chats where the contact sent something, the latest received first. */
    @GET("whatsapp/chats")
    suspend fun whatsappChats(@Query("page") page: Int = 1, @Query("per_page") perPage: Int = 20): Page<WhatsappChat>

    /** The latest messages of a chat, newest first. */
    @GET("whatsapp/chats/{id}/messages")
    suspend fun whatsappChatMessages(@Path("id") chatId: String): DataEnvelope<List<WhatsappChatMessage>>

    /** The answer to a message the contact sent, drafted by the AI in the user's style. */
    @POST("whatsapp/chat-messages/{id}/reply")
    suspend fun draftWhatsappReply(@Path("id") messageId: String, @Body body: WhatsappReplyRequest): WhatsappReplyDraft

    // WhatsApp chats

    /** Every chat, pinned first, then the latest message first ([sort] `priority`: the highest priority first). */
    @GET("whatsapp/conversations")
    suspend fun whatsappConversations(
        @Query("search") search: String? = null,
        @Query("tag") tag: String? = null,
        @Query("sort") sort: String? = null,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 30,
        @Query("account") account: String? = null,
        @Query("archived") archived: Int? = null,
    ): Page<WhatsappConversation>

    /** 40 messages, newest first, older than [before] when given. */
    @GET("whatsapp/conversations/{id}/messages")
    suspend fun whatsappConversationMessages(@Path("id") chatId: String, @Query("before") before: String? = null): WhatsappConversationMessagesPage

    @POST("whatsapp/conversations/{id}/messages")
    suspend fun sendWhatsappConversationText(@Path("id") chatId: String, @Body body: WhatsappConversationText): DataEnvelope<WhatsappDelivery>

    /** A file with its caption, answering [replyTo] when given (not with a voice note). */
    @Multipart
    @POST("whatsapp/conversations/{id}/messages")
    suspend fun sendWhatsappConversationFile(
        @Path("id") chatId: String,
        @Part("text") text: RequestBody,
        @Part file: MultipartBody.Part,
        @Part("reply_to") replyTo: RequestBody? = null,
    ): DataEnvelope<WhatsappDelivery>

    /** The text or caption of a message the number sent, within WhatsApp's 15 minutes. */
    @PATCH("whatsapp/conversations/{id}/messages/{message}")
    suspend fun editWhatsappMessage(@Path("id") chatId: String, @Path("message") messageId: String, @Body body: WhatsappMessageEdit): DataEnvelope<WhatsappConversationMessage>

    /** Deleted for everyone; Jarvis keeps it, marked `revoked`. */
    @DELETE("whatsapp/conversations/{id}/messages/{message}")
    suspend fun deleteWhatsappMessageForEveryone(@Path("id") chatId: String, @Path("message") messageId: String): DataEnvelope<WhatsappConversationMessage>

    /** Starred or not, in Jarvis only. */
    @POST("whatsapp/conversations/{id}/messages/{message}/star")
    suspend fun starWhatsappMessage(@Path("id") chatId: String, @Path("message") messageId: String, @Body body: WhatsappStarInput): DataEnvelope<WhatsappConversationMessage>

    @GET("whatsapp/conversations/{id}/messages/{message}/info")
    suspend fun whatsappMessageInfo(@Path("id") chatId: String, @Path("message") messageId: String): DataEnvelope<WhatsappMessageInfo>

    /** The messages with every word of [query] (2 characters at least), newest first. */
    @GET("whatsapp/conversations/{id}/search")
    suspend fun searchWhatsappConversation(@Path("id") chatId: String, @Query("q") query: String): WhatsappMessageList

    @GET("whatsapp/conversations/{id}/starred")
    suspend fun starredWhatsappMessages(@Path("id") chatId: String): WhatsappMessageList

    /** [kind] `media` (photos and videos), `documents` or `links`, 50 at a time, older than [before]. */
    @GET("whatsapp/conversations/{id}/gallery")
    suspend fun whatsappGallery(@Path("id") chatId: String, @Query("kind") kind: String, @Query("before") before: String? = null): WhatsappMessageList

    /** A poll; voting is done on the phone (the Evolution API cannot vote). */
    @POST("whatsapp/conversations/{id}/poll")
    suspend fun sendWhatsappPoll(@Path("id") chatId: String, @Body body: WhatsappPollInput): DataEnvelope<WhatsappDelivery>

    @POST("whatsapp/conversations/{id}/location")
    suspend fun sendWhatsappLocation(@Path("id") chatId: String, @Body body: WhatsappLocationInput): DataEnvelope<WhatsappDelivery>

    /** Contact cards of contact chats of the same number. */
    @POST("whatsapp/conversations/{id}/contacts")
    suspend fun sendWhatsappContacts(@Path("id") chatId: String, @Body body: WhatsappContactsInput): DataEnvelope<WhatsappDelivery>

    /** An image as a sticker: a WebP as it is, a PNG or JPEG made still (up to 1 MB). */
    @Multipart
    @POST("whatsapp/conversations/{id}/sticker")
    suspend fun sendWhatsappSticker(
        @Path("id") chatId: String,
        @Part file: MultipartBody.Part,
        @Part("reply_to") replyTo: RequestBody? = null,
    ): DataEnvelope<WhatsappDelivery>

    /** A group on one of the user's numbers, the number its admin. */
    @POST("whatsapp/groups")
    suspend fun createWhatsappGroup(@Body body: WhatsappGroupInput): DataEnvelope<WhatsappConversation>

    /** The group as WhatsApp has it now: permissions and members. */
    @GET("whatsapp/conversations/{id}/group")
    suspend fun whatsappGroup(@Path("id") chatId: String): DataEnvelope<WhatsappGroup>

    /** Any of `subject`, `description`, `admins_only`, `info_admins_only`, `ephemeral_seconds`; the ones left out stay. */
    @PATCH("whatsapp/conversations/{id}/group")
    suspend fun updateWhatsappGroup(@Path("id") chatId: String, @Body body: JsonObject): DataEnvelope<WhatsappGroup>

    @Multipart
    @POST("whatsapp/conversations/{id}/group/picture")
    suspend fun updateWhatsappGroupPicture(@Path("id") chatId: String, @Part file: MultipartBody.Part)

    @POST("whatsapp/conversations/{id}/group/members")
    suspend fun updateWhatsappGroupMembers(@Path("id") chatId: String, @Body body: WhatsappGroupMembersInput): WhatsappGroupMembersResult

    /** The link to join; a new one with `revoke`. */
    @POST("whatsapp/conversations/{id}/group/invite")
    suspend fun whatsappGroupInvite(@Path("id") chatId: String, @Body body: WhatsappGroupInviteInput): WhatsappGroupInvite

    /** The number leaves; the chat stays, archived. */
    @POST("whatsapp/conversations/{id}/group/leave")
    suspend fun leaveWhatsappGroup(@Path("id") chatId: String): DataEnvelope<WhatsappConversation>

    /** The user's reaction to a message; the same emoji again, or an empty one, removes it. */
    @POST("whatsapp/conversations/{id}/messages/{message}/reaction")
    suspend fun reactToWhatsappMessage(@Path("id") chatId: String, @Path("message") messageId: String, @Body body: WhatsappReactionInput): WhatsappReacted

    /** Messages of the chat sent again to other chats of the same number (WhatsApp does not mark them forwarded). */
    @POST("whatsapp/conversations/{id}/forward")
    suspend fun forwardWhatsappMessages(@Path("id") chatId: String, @Body body: WhatsappForwardInput): WhatsappForwarded

    @POST("whatsapp/conversations/{id}/read")
    suspend fun readWhatsappConversation(@Path("id") chatId: String)

    @POST("whatsapp/conversations/{id}/typing")
    suspend fun whatsappTyping(@Path("id") chatId: String): WhatsappTypingShown

    /** The chat with a number on one of the user's numbers, started when there is none. */
    @POST("whatsapp/conversations")
    suspend fun startWhatsappConversation(@Body body: WhatsappStartChat): DataEnvelope<WhatsappConversation>

    @GET("whatsapp/conversations/{id}")
    suspend fun whatsappConversation(@Path("id") chatId: String): WhatsappConversationDetail

    /**
     * Any of `pinned`, `priority`, `nickname`, `tags`, `sends_read_receipts`,
     * `sends_typing`, `notifies_messages`, `archived`, `marked_unread`,
     * `muted` and `blocked`; the ones left out stay. 502 when WhatsApp refuses.
     */
    @PATCH("whatsapp/conversations/{id}")
    suspend fun updateWhatsappConversation(@Path("id") chatId: String, @Body body: JsonObject): DataEnvelope<WhatsappConversation>

    @POST("whatsapp/conversations/{id}/sync")
    suspend fun syncWhatsappConversation(@Path("id") chatId: String): WhatsappSyncResult

    /** An answer drafted by the AI; nothing is sent. */
    @POST("whatsapp/conversations/{id}/draft")
    suspend fun draftWhatsappConversation(@Path("id") chatId: String, @Body body: WhatsappDraftRequest): WhatsappReplyDraft

    @POST("whatsapp/conversations/{id}/reminders")
    suspend fun remindWhatsappConversation(@Path("id") chatId: String, @Body body: WhatsappChatReminderInput): DataEnvelope<WhatsappChatReminder>

    /** The text sent once at a time; cancelled with [cancelWhatsappMessage]. */
    @POST("whatsapp/conversations/{id}/schedule")
    suspend fun scheduleWhatsappConversation(@Path("id") chatId: String, @Body body: WhatsappScheduleText): DataEnvelope<WhatsappScheduledText>

    /** A file sent once at a time, with [text] as its caption (empty for a voice note). */
    @Multipart
    @POST("whatsapp/conversations/{id}/schedule")
    suspend fun scheduleWhatsappConversationFile(
        @Path("id") chatId: String,
        @Part("text") text: RequestBody,
        @Part("at") at: RequestBody,
        @Part file: MultipartBody.Part,
    ): DataEnvelope<WhatsappScheduledText>

    @GET("whatsapp/tags")
    suspend fun whatsappTags(): DataEnvelope<List<WhatsappConversationTag>>

    @POST("whatsapp/tags")
    suspend fun createWhatsappTag(@Body body: WhatsappTagInput): DataEnvelope<WhatsappConversationTag>

    @PATCH("whatsapp/tags/{id}")
    suspend fun updateWhatsappTag(@Path("id") tagId: String, @Body body: WhatsappTagInput): DataEnvelope<WhatsappConversationTag>

    /** Off every chat too. */
    @DELETE("whatsapp/tags/{id}")
    suspend fun deleteWhatsappTag(@Path("id") tagId: String)

    @GET("whatsapp/priority-colors")
    suspend fun whatsappPriorityColors(): WhatsappPriorityColors

    @PUT("whatsapp/priority-colors")
    suspend fun saveWhatsappPriorityColors(@Body body: WhatsappPriorityColorsInput): WhatsappPriorityColors

    /** The defaults of the number's chats: any of `sends_read_receipts`, `sends_typing`, `notifies_messages`, `syncs_phone_reads`. */
    @PATCH("whatsapp/chat-settings/{account}")
    suspend fun updateWhatsappChatSettings(@Path("account") accountId: String, @Body body: JsonObject): DataEnvelope<WhatsappAccount>

    // Voice

    /** The spoken text to the server's interpreter (US-10.2). */
    @POST("voice/commands")
    suspend fun interpretVoiceCommand(@Body body: VoiceCommandRequest): VoiceCommandResult

    /** An action structured on the phone, or the answer to a clarification. */
    @POST("voice/actions")
    suspend fun runVoiceAction(@Body body: VoiceAction): VoiceCommandResult

    /** Sends the WhatsApp message of a `needs_confirmation` answer (US-10.3). */
    @POST("voice/commands/confirm")
    suspend fun confirmVoiceCommand(@Body body: VoiceConfirmRequest): VoiceCommandResult

    // Admin (super admin only; anyone else gets a 403)

    @GET("admin/modules")
    suspend fun adminModules(): DataEnvelope<List<AdminModule>>

    @GET("admin/workspaces")
    suspend fun adminWorkspaces(): DataEnvelope<List<AdminWorkspace>>

    @GET("admin/workspaces/{id}")
    suspend fun adminWorkspace(@Path("id") workspaceId: String): DataEnvelope<AdminWorkspaceDetail>

    @POST("admin/workspaces")
    suspend fun createAdminWorkspace(@Body body: AdminWorkspaceInput): DataEnvelope<AdminWorkspace>

    @PATCH("admin/workspaces/{id}")
    suspend fun updateAdminWorkspace(@Path("id") workspaceId: String, @Body body: AdminWorkspaceInput): DataEnvelope<AdminWorkspaceDetail>

    /** Refused (422 on `workspace`) while the workspace has data. */
    @DELETE("admin/workspaces/{id}")
    suspend fun deleteAdminWorkspace(@Path("id") workspaceId: String)

    /** Adds an existing user; [AdminMemberInput.user] is their friendly id. */
    @POST("admin/workspaces/{id}/members")
    suspend fun addAdminWorkspaceMember(@Path("id") workspaceId: String, @Body body: AdminMemberInput): DataEnvelope<AdminWorkspaceMember>

    @PATCH("admin/workspaces/{id}/members/{user}")
    suspend fun updateAdminWorkspaceMember(
        @Path("id") workspaceId: String,
        @Path("user") userId: String,
        @Body body: AdminMemberInput,
    ): DataEnvelope<AdminWorkspaceMember>

    @DELETE("admin/workspaces/{id}/members/{user}")
    suspend fun removeAdminWorkspaceMember(@Path("id") workspaceId: String, @Path("user") userId: String)

    @GET("admin/users")
    suspend fun adminUsers(): DataEnvelope<List<AdminUser>>

    /** Signs the user out everywhere; they cannot sign in until reactivated. */
    @POST("admin/users/{id}/deactivate")
    suspend fun deactivateAdminUser(@Path("id") userId: String): DataEnvelope<AdminUser>

    @POST("admin/users/{id}/reactivate")
    suspend fun reactivateAdminUser(@Path("id") userId: String): DataEnvelope<AdminUser>

    @GET("admin/invitations")
    suspend fun adminInvitations(): DataEnvelope<List<AdminInvitation>>

    @POST("admin/invitations")
    suspend fun createAdminInvitation(@Body body: AdminInvitationInput): DataEnvelope<AdminInvitation>

    @POST("admin/invitations/{id}/resend")
    suspend fun resendAdminInvitation(@Path("id") invitationId: String): DataEnvelope<AdminInvitation>

    @POST("admin/invitations/{id}/revoke")
    suspend fun revokeAdminInvitation(@Path("id") invitationId: String): DataEnvelope<AdminInvitation>

    // Realtime

    @POST("broadcasting/auth")
    suspend fun broadcastingAuth(@Body body: BroadcastAuthRequest): BroadcastAuthorization
}
