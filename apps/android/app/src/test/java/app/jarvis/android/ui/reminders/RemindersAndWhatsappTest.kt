package app.jarvis.android.ui.reminders

import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.CalendarSource
import app.jarvis.android.network.RecurrenceFrequency
import app.jarvis.android.network.ReminderFilter
import app.jarvis.android.network.ReminderKind
import app.jarvis.android.network.ReminderOccurrencePage
import app.jarvis.android.network.WhatsappAccount
import app.jarvis.android.network.WhatsappAccountSummary
import app.jarvis.android.network.WhatsappMessage
import app.jarvis.android.network.WhatsappMessageState
import app.jarvis.android.network.WhatsappTarget
import app.jarvis.android.network.Weekday
import app.jarvis.android.support.StubTransport
import app.jarvis.android.ui.schedule.ScheduleDraft
import app.jarvis.android.ui.whatsapp.WhatsappComposer
import app.jarvis.android.ui.whatsapp.parseNumbers
import app.jarvis.android.ui.whatsapp.saveWhatsappMessage
import app.jarvis.android.ui.whatsapp.selectableAccounts
import app.jarvis.android.ui.whatsapp.whatsappMessageInput
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test
import java.time.LocalDateTime
import java.time.LocalTime
import java.time.ZoneId
import java.time.ZonedDateTime

class RemindersAndWhatsappTest {
    private val saoPaulo = ZoneId.of("America/Sao_Paulo")
    private val transport = StubTransport()

    private fun lastBody(): JsonObject = Json.parseToJsonElement(transport.bodies.last()!!).jsonObject

    private fun page(filter: String, vararg ids: String) =
        """{"data":[${ids.joinToString(",") { occurrence(it) }}],"meta":{"filter":"$filter","counts":{"pending":4,"overdue":1,"completed":12},"timezone":"America/Sao_Paulo"}}"""

    private fun occurrence(id: String, completed: Boolean = false) =
        """{"friendly_id":"$id","due_at":"2026-10-06T12:00:00+00:00","is_completed":$completed,"is_overdue":true,"completed_at":null,"completed_by":null,
          "reminder":{"friendly_id":"rem_$id","title":"Pay rent","note":null,"is_recurring":true,
            "recurrence":{"frequency":"weekly","time_of_day":"09:00","weekdays":["monday","friday"],"day_of_month":null,
              "is_last_day_of_month":false,"cron_expression":null,"timezone":"America/Sao_Paulo",
              "starts_at":"2026-10-01T12:00:00+00:00","ends_at":null,"next_run_at":"2026-10-09T12:00:00+00:00","is_paused":false},
            "created_by":{"friendly_id":"usr_1","name":"Ana"},"created_at":null,"updated_at":null}}"""

    private fun message(state: String, frequency: String) = ApiClient.json.decodeFromString<WhatsappMessage>(
        """{"friendly_id":"wmsg_1","account":{"friendly_id":"wacc_1","nickname":"Personal"},
        "targets":[{"friendly_id":"wtg_1","name":"Ana","type":"contact","phone_number":"+5511999990000","is_synced":true}],
        "body":"Hi","state":"$state",
        "recurrence":{"frequency":"$frequency","time_of_day":null,"weekdays":[],"day_of_month":null,"is_last_day_of_month":false,
          "cron_expression":null,"timezone":"UTC","starts_at":"2026-10-06T12:00:00+00:00","ends_at":null,"next_run_at":null,"is_paused":false},
        "cancelled_at":null,"created_at":null,"updated_at":null}""",
    )

    @Test
    fun `the reminders page carries the counts of every filter`() {
        val page = ApiClient.json.decodeFromString<ReminderOccurrencePage>(page("overdue", "remo_1"))

        assertEquals(4, page.count(ReminderFilter.Pending))
        assertEquals(1, page.count(ReminderFilter.Overdue))
        assertEquals(12, page.count(ReminderFilter.Completed))
        val recurrence = page.data.first().reminder.recurrence!!
        assertEquals(RecurrenceFrequency.Weekly, recurrence.frequency)
        assertEquals(listOf(Weekday.Monday, Weekday.Friday), recurrence.weekdays)
        assertEquals("09:00", recurrence.timeOfDay)
    }

    @Test
    fun `the filters load like the web and completing reads the filter again`() = runTest {
        transport.enqueue(200, page("pending", "remo_1", "remo_2"))
        transport.enqueue(200, page("overdue", "remo_3"))
        transport.enqueue(200, """{"data":${occurrence("remo_3", completed = true)}}""")
        transport.enqueue(200, page("overdue"))
        val store = RemindersStore(transport.client(), mode = RemindersStore.Mode.List)

        store.load()
        assertEquals(listOf("remo_1", "remo_2"), store.state.value.occurrences?.map { it.friendlyId })
        assertEquals(mapOf(ReminderFilter.Pending to 4, ReminderFilter.Overdue to 1, ReminderFilter.Completed to 12), store.state.value.counts)

        store.show(ReminderFilter.Overdue)
        store.toggleCompletion(store.state.value.occurrences!!.single())

        assertEquals(
            listOf("/api/v1/reminders?filter=pending", "/api/v1/reminders?filter=overdue", "/api/v1/reminder-occurrences/remo_3/complete", "/api/v1/reminders?filter=overdue"),
            transport.requests.map { request -> request.url.encodedPath + request.url.encodedQuery?.let { "?$it" }.orEmpty() },
        )
        assertEquals(emptyList<Any>(), store.state.value.occurrences)
    }

    @Test
    fun `reopening a completed occurrence calls reopen`() = runTest {
        transport.enqueue(200, """{"data":[${occurrence("remo_1", completed = true)}],"meta":{"counts":{}}}""")
        transport.enqueue(200, """{"data":${occurrence("remo_1")}}""")
        transport.enqueue(200, """{"data":[],"meta":{"counts":{}}}""")
        val store = RemindersStore(transport.client(), mode = RemindersStore.Mode.List)
        store.show(ReminderFilter.Completed)

        store.toggleCompletion(store.state.value.occurrences!!.single())

        assertEquals("/api/v1/reminder-occurrences/remo_1/reopen", transport.requests[1].url.encodedPath)
        assertEquals("POST", transport.requests[1].method)
    }

    @Test
    fun `a reminder is sent whole with the date on the users wall clock`() = runTest {
        transport.enqueue(200, page("pending", "remo_1"))
        transport.enqueue(200, """{"data":{"friendly_id":"rem_remo_1","title":"Dentist","note":null,"is_recurring":false,"recurrence":null}}""")
        val client = transport.client()
        val occurrence = RemindersStore(client, mode = RemindersStore.Mode.List).run {
            load()
            state.value.occurrences!!.single()
        }
        val once = ScheduleDraft.new(ScheduleDraft.Kind.Once, ZonedDateTime.now(saoPaulo)).copy(onceAt = LocalDateTime.of(2026, 10, 6, 9, 0))

        client.saveReminder(occurrence.reminder, reminderInput("  Dentist ", "  ", once, assignedTo = "usr_2"))

        assertEquals("PATCH", transport.requests.last().method)
        assertEquals("/api/v1/reminders/rem_remo_1", transport.requests.last().url.encodedPath)
        val json = lastBody()
        assertEquals("Dentist", json.getValue("title").jsonPrimitive.content)
        // Without an offset: the server reads it in the user's timezone, as the web form sends it.
        assertEquals("2026-10-06T09:00", json.getValue("due_at").jsonPrimitive.content)
        // An edit replaces the whole reminder, so the cleared fields go as null.
        assertEquals(JsonNull, json.getValue("note"))
        assertEquals(JsonNull, json.getValue("recurrence"))
        assertEquals("usr_2", json.getValue("assigned_to").jsonPrimitive.content)
    }

    @Test
    fun `nobody responsible is sent as null`() = runTest {
        transport.enqueue(201, """{"data":{"friendly_id":"rem_1","title":"Shared chore"}}""")
        val none = ScheduleDraft.new(ScheduleDraft.Kind.None, ZonedDateTime.now(saoPaulo))

        transport.client().saveReminder(null, reminderInput("Shared chore", "", none, assignedTo = null))

        // Left out, the server would make it the creator's: null means nobody.
        assertEquals(JsonNull, lastBody().getValue("assigned_to"))
    }

    @Test
    fun `only mine and the list of the next 30 days ask the server for the users own`() = runTest {
        transport.enqueue(200, page("pending", "remo_1"))
        transport.enqueue(200, page("pending"))
        transport.enqueue(200, calendar)
        val store = RemindersStore(transport.client(), saoPaulo, mode = RemindersStore.Mode.List, today = java.time.LocalDate.of(2026, 10, 6))

        store.load()
        store.showOnlyMine(true)
        store.show(RemindersStore.Mode.Schedule)

        assertEquals(
            listOf(
                "/api/v1/reminders?filter=pending",
                "/api/v1/reminders?filter=pending&mine=1",
                "/api/v1/agenda?from=2026-10-06T00%3A00%3A00-03%3A00&to=2026-11-05T00%3A00%3A00-03%3A00&mine=1",
            ),
            transport.requests.map { request -> request.url.encodedPath + request.url.encodedQuery?.let { "?$it" }.orEmpty() },
        )
        assertEquals(listOf("Ana"), store.state.value.assignees.map { it.name })
    }

    @Test
    fun `the views step through their periods and a tap on a week day opens it`() = runTest {
        repeat(4) { transport.enqueue(200, calendar) }
        val store = RemindersStore(transport.client(), saoPaulo, mode = RemindersStore.Mode.Week, today = java.time.LocalDate.of(2026, 10, 6))

        store.load()
        store.step(1)
        // A day of the same week loads nothing new.
        store.show(java.time.LocalDate.of(2026, 10, 14))
        store.open(java.time.LocalDate.of(2026, 10, 15))

        assertEquals(
            listOf(
                "from=2026-10-04T00%3A00%3A00-03%3A00&to=2026-10-11T00%3A00%3A00-03%3A00",
                "from=2026-10-11T00%3A00%3A00-03%3A00&to=2026-10-18T00%3A00%3A00-03%3A00",
                "from=2026-10-15T00%3A00%3A00-03%3A00&to=2026-10-16T00%3A00%3A00-03%3A00",
            ),
            transport.requests.map { it.url.encodedQuery },
        )
        assertEquals(RemindersStore.Mode.Day, store.state.value.mode)
    }

    @Test
    fun `overlapping items share the hour side by side`() {
        fun entry(key: String, startsAt: String, endsAt: String? = null, isAllDay: Boolean = false) =
            app.jarvis.android.network.CalendarEntry(key = key, source = "events", title = key, startsAt = startsAt, endsAt = endsAt, isAllDay = isAllDay)

        val slots = DayTimeline.layout(
            listOf(
                // 09:00–10:30 and 09:30–10:00 overlap; 11:00 is alone; a moment takes the minimum; 22:00 is cut at midnight.
                entry("long", "2026-10-06T12:00:00+00:00", "2026-10-06T13:30:00+00:00"),
                entry("short", "2026-10-06T12:30:00+00:00", "2026-10-06T13:00:00+00:00"),
                entry("alone", "2026-10-06T14:00:00+00:00"),
                entry("late", "2026-10-07T01:00:00+00:00", "2026-10-07T05:00:00+00:00"),
                entry("holiday", "2026-10-06T03:00:00+00:00", isAllDay = true),
            ),
            java.time.LocalDate.of(2026, 10, 6),
            saoPaulo,
        ).associateBy { it.entry.key }

        assertNull(slots["holiday"])
        assertEquals(listOf(9 * 60, 90, 0, 2), slots.getValue("long").let { listOf(it.top, it.height, it.lane, it.lanes) })
        assertEquals(listOf(1, 2), slots.getValue("short").let { listOf(it.lane, it.lanes) })
        assertEquals(listOf(11 * 60, DayTimeline.MIN_MINUTES, 0, 1), slots.getValue("alone").let { listOf(it.top, it.height, it.lane, it.lanes) })
        assertEquals(listOf(22 * 60, 120), slots.getValue("late").let { listOf(it.top, it.height) })
    }

    private val calendar =
        """{"data":[
          {"key":"remo_1","source":"reminders","title":"Pay the school","subtitle":null,"starts_at":"2026-10-06T13:00:00+00:00",
           "ends_at":null,"is_all_day":false,"is_projected":false,"is_completable":true,"is_completed":false,"is_overdue":false,
           "reminder_id":"rem_1","occurrence_id":"remo_1","url":null},
          {"key":"gev_1","source":"birthdays","title":"Someone new","starts_at":"2026-10-07T03:00:00+00:00","is_all_day":true,
           "url":"https://calendar.google.com/event?eid=1"}],
        "meta":{"from":"2026-09-27T03:00:00+00:00","to":"2026-11-08T03:00:00+00:00","mine":false,
          "sources":["reminders","events","google"],"timezone":"America/Sao_Paulo","assignees":[{"friendly_id":"usr_1","name":"Ana"}]}}"""

    @Test
    fun `the calendar asks for the six weeks of the month and completes from the day`() = runTest {
        transport.enqueue(200, calendar)
        transport.enqueue(200, """{"data":[],"meta":{}}""")
        transport.enqueue(200, """{"data":${occurrence("remo_1", completed = true)}}""")
        transport.enqueue(200, calendar)
        val store = RemindersStore(transport.client(), saoPaulo, today = java.time.LocalDate.of(2026, 10, 6))

        store.load()

        val state = store.state.value
        // Six weeks from the Sunday on or before October 1st.
        assertEquals(java.time.LocalDate.of(2026, 9, 27), state.month.start)
        assertEquals(42, state.month.days.size)
        assertEquals(listOf(CalendarSource.Reminders, CalendarSource.Events, CalendarSource.Google), state.sources)
        assertEquals(listOf("Ana"), state.assignees.map { it.name })
        assertEquals(java.time.LocalDate.of(2026, 10, 6), state.entries!![0].day(saoPaulo))
        // A source this app does not know yet still decodes, without a color of its own.
        assertNull(state.entries[1].calendarSource)

        store.toggle(CalendarSource.Google)
        store.toggleCompletion(state.entries[0])

        assertEquals(
            listOf(
                "/api/v1/agenda?from=2026-09-27T00%3A00%3A00-03%3A00&to=2026-11-08T00%3A00%3A00-03%3A00",
                "/api/v1/agenda?from=2026-09-27T00%3A00%3A00-03%3A00&to=2026-11-08T00%3A00%3A00-03%3A00&hide%5B%5D=google",
                "/api/v1/reminder-occurrences/remo_1/complete",
                "/api/v1/agenda?from=2026-09-27T00%3A00%3A00-03%3A00&to=2026-11-08T00%3A00%3A00-03%3A00&hide%5B%5D=google",
            ),
            transport.requests.map { request -> request.url.encodedPath + request.url.encodedQuery?.let { "?$it" }.orEmpty() },
        )
    }

    @Test
    fun `an event is sent with its kind and duration, and an all day task without a date is not`() = runTest {
        transport.enqueue(201, """{"data":{"friendly_id":"rem_1","title":"Concert","kind":"event","duration_minutes":120}}""")
        transport.enqueue(201, """{"data":{"friendly_id":"rem_2","title":"Chore"}}""")
        val once = ScheduleDraft.new(ScheduleDraft.Kind.Once, ZonedDateTime.now(saoPaulo)).copy(onceAt = LocalDateTime.of(2026, 10, 9, 20, 0))

        transport.client().saveReminder(null, reminderInput("Concert", "", once, null, ReminderKind.Event, isAllDay = false, durationMinutes = 120))

        assertEquals("event", lastBody().getValue("kind").jsonPrimitive.content)
        assertEquals("false", lastBody().getValue("is_all_day").jsonPrimitive.content)
        assertEquals("120", lastBody().getValue("duration_minutes").jsonPrimitive.content)

        val none = ScheduleDraft.new(ScheduleDraft.Kind.None, ZonedDateTime.now(saoPaulo))
        transport.client().saveReminder(null, reminderInput("Chore", "", none, null, isAllDay = true, durationMinutes = 30))

        assertEquals("reminder", lastBody().getValue("kind").jsonPrimitive.content)
        assertEquals("false", lastBody().getValue("is_all_day").jsonPrimitive.content)
        assertEquals(JsonNull, lastBody().getValue("duration_minutes"))
    }

    @Test
    fun `a repeating schedule is sent with its preset fields`() = runTest {
        transport.enqueue(201, """{"data":{"friendly_id":"rem_1","title":"Gym","is_recurring":true}}""")
        val cron = ScheduleDraft.new(ScheduleDraft.Kind.CustomCron, ZonedDateTime.now(saoPaulo))
            .copy(cronExpression = " 0 7 * * 1-5 ", hasEnd = true, endsAt = LocalDateTime.of(2026, 12, 31, 23, 0))

        transport.client().saveReminder(null, reminderInput("Gym", "", cron, assignedTo = null))

        assertEquals("POST", transport.requests.last().method)
        assertEquals("/api/v1/reminders", transport.requests.last().url.encodedPath)
        val json = lastBody()
        assertEquals(JsonNull, json.getValue("due_at"))
        val recurrence = json.getValue("recurrence").jsonObject
        assertEquals("custom_cron", recurrence.getValue("frequency").jsonPrimitive.content)
        assertEquals("0 7 * * 1-5", recurrence.getValue("cron_expression").jsonPrimitive.content)
        assertEquals("2026-12-31T23:00", recurrence.getValue("ends_at").jsonPrimitive.content)
        assertTrue(recurrence["time_of_day"] == null || recurrence["time_of_day"] == JsonNull)
    }

    @Test
    fun `the weekly and monthly presets carry their days`() {
        val now = ZonedDateTime.of(2026, 10, 4, 10, 20, 0, 0, saoPaulo)
        val weekly = ScheduleDraft.new(ScheduleDraft.Kind.Weekly, now)
            .copy(time = LocalTime.of(9, 30), weekdays = setOf(Weekday.Friday, Weekday.Monday))
            .recurrenceInput()!!
        val monthly = ScheduleDraft.new(ScheduleDraft.Kind.Monthly, now).copy(isLastDayOfMonth = true).recurrenceInput()!!

        assertEquals("09:30", weekly.timeOfDay)
        // In the order of the week, whatever order they were tapped in.
        assertEquals(listOf(Weekday.Monday, Weekday.Friday), weekly.weekdays)
        assertNull(weekly.endsAt)
        assertEquals(true, monthly.isLastDayOfMonth)
        assertNull(monthly.dayOfMonth)
        assertEquals("11:00", monthly.timeOfDay)
        assertNull(ScheduleDraft.new(ScheduleDraft.Kind.None, now).recurrenceInput())
    }

    @Test
    fun `a saved schedule is read on the users wall clock`() {
        val page = ApiClient.json.decodeFromString<ReminderOccurrencePage>(page("pending", "remo_1"))
        val draft = ScheduleDraft.of(page.data.first().reminder.recurrence, saoPaulo)

        assertEquals(ScheduleDraft.Kind.Weekly, draft.kind)
        assertEquals(LocalTime.of(9, 0), draft.time)
        assertEquals(setOf(Weekday.Monday, Weekday.Friday), draft.weekdays)

        val once = ScheduleDraft.of(message("active", "once").recurrence, saoPaulo)
        // 12:00 UTC is 09:00 in São Paulo.
        assertEquals(LocalDateTime.of(2026, 10, 6, 9, 0), once.onceAt)
        assertEquals("2026-10-06T09:00", once.recurrenceInput()!!.startsAt)
    }

    @Test
    fun `a past schedule shows the servers translated message`() = runTest {
        transport.enqueue(422, """{"message":"A data e a hora precisam estar no futuro.","errors":{"recurrence.starts_at":["A data e a hora precisam estar no futuro."]}}""")
        val past = ScheduleDraft.new(ScheduleDraft.Kind.Once, ZonedDateTime.now(saoPaulo)).copy(onceAt = LocalDateTime.of(2020, 1, 1, 9, 0))
        val input = whatsappMessageInput("wacc_1", listOf(WhatsappTarget("wtg_1", "Ana")), "", "Hi {% recipient.name %}!", past)

        try {
            transport.client(acceptLanguage = { "pt-BR" }).saveWhatsappMessage(WhatsappComposer.Schedule(null), input)
            fail("A 422 must throw.")
        } catch (exception: ApiException) {
            assertEquals("A data e a hora precisam estar no futuro.", exception.displayMessage)
            assertEquals("A data e a hora precisam estar no futuro.", exception.firstError("recurrence.starts_at"))
        }

        assertEquals("pt-BR", transport.requests.single().header("Accept-Language"))
        assertEquals("2020-01-01T09:00", lastBody().getValue("recurrence").jsonObject.getValue("starts_at").jsonPrimitive.content)
    }

    @Test
    fun `sending now leaves the schedule out and counts the deliveries`() = runTest {
        transport.enqueue(
            202,
            """{"data":[{"account":{"friendly_id":"wacc_1","nickname":"Personal"},"target":{"friendly_id":"wtg_9","name":"+5511999990000","type":"contact"},
            "message_id":null,"body":"Hi","source":"manual","status":"pending","error_message":null,"scheduled_for":null,"sent_at":null,"created_at":null}]}""",
        )
        val daily = ScheduleDraft.new(ScheduleDraft.Kind.Daily, ZonedDateTime.now(saoPaulo))
        val input = whatsappMessageInput("wacc_1", emptyList(), " +5511999990000 ;\n", " Hi ", daily)

        val sent = transport.client().saveWhatsappMessage(WhatsappComposer.SendNow, input)

        assertEquals(1, sent)
        assertEquals("/api/v1/whatsapp/send-now", transport.requests.last().url.encodedPath)
        val json = lastBody()
        assertEquals(listOf("+5511999990000"), json.getValue("numbers").jsonArray.map { it.jsonPrimitive.content })
        assertEquals("Hi", json.getValue("body").jsonPrimitive.content)
        assertFalse(json.containsKey("recurrence"))
    }

    @Test
    fun `editing a message replaces it and the actions follow its state`() = runTest {
        transport.enqueue(200, """{"data":${ApiClient.json.encodeToString(WhatsappMessage.serializer(), message("active", "daily"))}}""")
        val edited = message("active", "daily")
        val input = whatsappMessageInput("wacc_1", edited.targets, "", "Bom dia", ScheduleDraft.of(edited.recurrence, saoPaulo))

        assertNull(transport.client().saveWhatsappMessage(WhatsappComposer.Schedule(edited), input))
        assertEquals("PATCH", transport.requests.last().method)
        assertEquals("/api/v1/whatsapp/messages/wmsg_1", transport.requests.last().url.encodedPath)
        assertEquals(listOf("wtg_1"), lastBody().getValue("targets").jsonArray.map { it.jsonPrimitive.content })

        val once = message("active", "once")
        assertTrue(once.canBeCancelled)
        assertTrue(once.canBePaused)
        assertFalse(message("active", "daily").canBeCancelled)
        val paused = message("paused", "daily")
        assertTrue(paused.canBeResumed)
        assertFalse(paused.canBePaused)
        val cancelled = message("cancelled", "once")
        assertFalse(cancelled.canBeEdited)
        assertFalse(cancelled.canBeCancelled)
        assertEquals(WhatsappMessageState.Cancelled, cancelled.state)
    }

    @Test
    fun `only connected numbers can send, plus the one of an edited message`() {
        val accounts = listOf(
            WhatsappAccount("wacc_1", "Personal", status = "open"),
            WhatsappAccount("wacc_2", "Work", status = "closed"),
        )
        val onWork = message("active", "daily").copy(account = WhatsappAccountSummary("wacc_2", "Work"))

        assertEquals(listOf("wacc_1"), selectableAccounts(accounts, null).map { it.friendlyId })
        assertEquals(listOf("wacc_1", "wacc_2"), selectableAccounts(accounts, onWork).map { it.friendlyId })
        assertEquals(listOf("+5511999990000", "+5521988887777"), parseNumbers("+5511999990000, +5521988887777\n\n;"))
    }
}
