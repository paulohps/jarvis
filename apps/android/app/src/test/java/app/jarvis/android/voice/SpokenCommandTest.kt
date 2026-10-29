package app.jarvis.android.voice

import app.jarvis.android.network.VoiceAction
import app.jarvis.android.network.VoiceActionName
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.time.LocalDateTime
import java.time.ZoneId

class SpokenCommandTest {
    @Test
    fun `each kind becomes the same action as the servers interpreter`() {
        assertEquals(
            VoiceAction(VoiceActionName.ShoppingAddItem, mapOf("item" to "leite", "quantity" to "2 litros", "list" to "dessa semana")),
            SpokenCommand.parse("""{"kind":"addShoppingItem","item":"leite","quantity":"2 litros","list":"dessa semana"}"""),
        )
        assertEquals(
            VoiceAction(VoiceActionName.ShoppingCheckItem, mapOf("item" to "pão")),
            SpokenCommand.parse("""{"kind":"checkShoppingItem","item":"pão","list":null}"""),
        )
        assertEquals(
            VoiceAction(VoiceActionName.RemindersCreate, mapOf("title" to "pagar o aluguel", "due_at" to "2026-10-06T09:00")),
            SpokenCommand.parse("""{"kind":"createReminder","title":"pagar o aluguel","dueAt":"2026-10-06T09:00"}"""),
        )
        assertEquals(
            VoiceAction(VoiceActionName.RemindersComplete, mapOf("reminder" to "aluguel")),
            SpokenCommand.parse("""{"kind":"completeReminder","reminder":"aluguel"}"""),
        )
        assertEquals(
            VoiceAction(VoiceActionName.WhatsappSend, mapOf("target" to "Ana", "body" to "Cheguei")),
            SpokenCommand.parse("""{"kind":"sendWhatsappMessage","target":"Ana","body":"Cheguei"}"""),
        )
    }

    @Test
    fun `code fences and prose around the JSON are ignored`() {
        assertEquals(
            VoiceAction(VoiceActionName.RemindersComplete, mapOf("reminder" to "gym")),
            SpokenCommand.parse("Here it is:\n```json\n{\"kind\": \"completeReminder\", \"reminder\": \"gym\"}\n```"),
        )
    }

    @Test
    fun `none, a missing required value or no JSON is nothing`() {
        assertNull(SpokenCommand.parse("""{"kind":"none"}"""))
        assertNull(SpokenCommand.parse("""{"kind":"sendWhatsappMessage","target":"Ana"}"""))
        assertNull(SpokenCommand.parse("""{"kind":"addShoppingItem","item":"  "}"""))
        assertNull(SpokenCommand.parse("I cannot help with that."))
        assertNull(SpokenCommand.parse("""{"kind":"""))
    }

    @Test
    fun `a due date not on the wall clock is left out`() {
        assertEquals(
            VoiceAction(VoiceActionName.RemindersCreate, mapOf("title" to "dentist")),
            SpokenCommand.parse("""{"kind":"createReminder","title":"dentist","dueAt":"tomorrow"}"""),
        )
    }

    @Test
    fun `the prompt carries the users wall clock and the command`() {
        val prompt = SpokenCommand.prompt("lembrar amanhã às 9h", LocalDateTime.of(2026, 10, 5, 14, 30), ZoneId.of("America/Sao_Paulo"))

        assertTrue(prompt.contains("2026-10-05T14:30 (America/Sao_Paulo)"))
        assertTrue(prompt.endsWith("Command: lembrar amanhã às 9h"))
    }
}
