import Foundation
import XCTest
@testable import Networking

final class WhatsappMessageActionsTests: XCTestCase {
    func testAMessageCarriesItsQuoteMarkersAndCards() throws {
        let page = try JSONCoding.decoder.decode(WhatsappConversationMessagesPage.self, from: Data("""
        {"data":[{"friendly_id":"wcm_1","from_me":true,"type":"conversation","body":"@Ana bora?","sent_at":"2026-10-06T12:00:00+00:00",
          "quoted":{"friendly_id":"wcm_0","sender":"Ana","from_me":false,"type":"conversation","body":"Vamos?"},
          "mentions":[{"jid":"5511911110000@s.whatsapp.net","name":"Ana"}],
          "forwarded":true,"edited":true,"can_edit":true,"can_delete_for_everyone":true,"pinned":true,"starred":true,"sticker":false,
          "location":null,"contacts":null,"poll":null},
         {"friendly_id":"wcm_2","from_me":false,"type":"locationMessage","body":null,"sent_at":"2026-10-06T12:01:00+00:00",
          "location":{"latitude":-23.5,"longitude":-46,"name":"Paulista","address":"Av. Paulista, 1000"}},
         {"friendly_id":"wcm_3","from_me":false,"type":"contactMessage","body":null,"sent_at":"2026-10-06T12:02:00+00:00",
          "contacts":[{"name":"Bruno","numbers":["+5511922220000"]}]},
         {"friendly_id":"wcm_4","from_me":false,"type":"pollCreationMessageV3","body":null,"sent_at":"2026-10-06T12:03:00+00:00",
          "poll":{"name":"Pizza?","selectable_count":0,"options":[{"name":"Sim","votes":2,"mine":true},{"name":"Não","votes":1,"mine":false}]}},
         {"friendly_id":"wcm_5","from_me":false,"type":"stickerMessage","body":null,"sent_at":"2026-10-06T12:04:00+00:00","sticker":true,
          "media":{"kind":"image","mime":"image/webp"}}],
         "outgoing":[],"meta":{"has_more":false}}
        """.utf8))

        let replied = page.data[0]
        XCTAssertEqual(replied.quoted, .init(friendlyId: "wcm_0", sender: "Ana", fromMe: false, type: "conversation", body: "Vamos?"))
        XCTAssertEqual(replied.mentions.map(\.name), ["Ana"])
        XCTAssertTrue(replied.forwarded && replied.edited && replied.canEdit && replied.canDeleteForEveryone && replied.pinned && replied.starred)
        XCTAssertFalse(replied.isSticker)

        XCTAssertEqual(page.data[1].location?.latitude, -23.5)
        XCTAssertEqual(page.data[1].location?.longitude, -46)
        XCTAssertEqual(page.data[1].location?.name, "Paulista")
        XCTAssertEqual(page.data[1].location?.isLive, false)
        XCTAssertEqual(page.data[2].contacts?.first?.numbers, ["+5511922220000"])

        let poll = try XCTUnwrap(page.data[3].poll)
        XCTAssertTrue(poll.allowsMultipleAnswers)
        XCTAssertEqual(poll.totalVotes, 3)
        XCTAssertEqual(poll.options.first?.mine, true)

        XCTAssertTrue(page.data[4].isSticker)
        XCTAssertFalse(page.data[2].starred)
        XCTAssertNil(page.data[2].quoted)
        XCTAssertEqual(page.data[2].mentions, [])
    }

    func testStarringAndReactingKeepTheRestOfTheMessage() {
        let message = WhatsappConversationMessage(friendlyId: "wcm_1", fromMe: true, body: "Oi", sentAt: .now, quoted: .init(friendlyId: "wcm_0", body: "Olá"), edited: true, canEdit: true)

        let starred = message.starring(true)
        XCTAssertTrue(starred.starred)
        XCTAssertEqual(starred.quoted, message.quoted)
        XCTAssertTrue(starred.reacting(with: "👍").edited)
        XCTAssertTrue(starred.reacting(with: "👍").starred)
    }

    func testAChatCarriesArchiveMuteBlockAndTimerAndOlderOnesDefaultThem() throws {
        let page = try JSONCoding.decoder.decode(Page<WhatsappConversation>.self, from: Data("""
        {"data":[{"friendly_id":"wtgt_1","name":"Família","is_group":true,"phone_number":null,"picture_url":null,
          "account":{"friendly_id":"wacc_1","nickname":"Personal"},"unread_count":0,"marked_unread":true,"pinned":false,
          "archived":true,"muted_until":"9999-12-31T00:00:00+00:00","blocked":false,"ephemeral_seconds":604800,
          "description":"Só a família","priority":null,"priority_color":null,"tags":[],"last_message":null,"last_message_at":null},
         {"friendly_id":"wtgt_2","name":"Ana","is_group":false,"phone_number":"+5511911110000","picture_url":null,
          "account":{"friendly_id":"wacc_1","nickname":"Personal"},"unread_count":2,"pinned":true,
          "priority":1,"priority_color":"#ff3b30","tags":[],"last_message":null,"last_message_at":null}],
         "meta":{"current_page":1,"last_page":1,"per_page":30,"total":2}}
        """.utf8))

        let group = page.data[0]
        XCTAssertTrue(group.markedUnread && group.archived && group.looksUnread)
        XCTAssertTrue(group.isMuted())
        XCTAssertEqual(group.ephemeralSeconds, 604_800)
        XCTAssertEqual(group.description, "Só a família")

        let contact = page.data[1]
        XCTAssertFalse(contact.archived || contact.markedUnread || contact.blocked || contact.isMuted())
        XCTAssertEqual(contact.ephemeralSeconds, 0)
        XCTAssertTrue(contact.looksUnread)
    }

    func testTheChatUpdateSendsArchiveUnreadMuteAndBlock() throws {
        var update = WhatsappConversationUpdate()
        update.archived = .set(true)
        update.markedUnread = .set(false)
        update.muted = .set(.oneWeek)
        update.blocked = .set(true)

        let body = try XCTUnwrap(API.updateWhatsappConversation(chatId: "wtgt_1", update).body)
        XCTAssertEqual(try JSONSerialization.jsonObject(with: body) as? NSDictionary, ["archived": true, "marked_unread": false, "muted": "1w", "blocked": true])

        var unmute = WhatsappConversationUpdate()
        unmute.muted = .set(nil)

        let unmuted = try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(API.updateWhatsappConversation(chatId: "wtgt_1", unmute).body)) as? [String: Any])
        XCTAssertTrue(unmuted["muted"] is NSNull)
        XCTAssertEqual(API.whatsappConversations(archived: true).query.last, URLQueryItem(name: "archived", value: "1"))
    }

    func testATextQuotesTheMessageItAnswersOnlyWhenThereIsOne() throws {
        let reply = try XCTUnwrap(API.sendWhatsappConversationText(chatId: "wtgt_1", text: " Sim ", replyTo: "wcm_1").body)
        let plain = try XCTUnwrap(API.sendWhatsappConversationText(chatId: "wtgt_1", text: "Sim").body)

        XCTAssertEqual(try JSONSerialization.jsonObject(with: reply) as? NSDictionary, ["text": "Sim", "reply_to": "wcm_1"])
        XCTAssertEqual(try JSONSerialization.jsonObject(with: plain) as? NSDictionary, ["text": "Sim"])
    }

    func testTheMessageActionsGoToTheirPathsWithTheirBodies() throws {
        let edit = API.editWhatsappMessage(chatId: "wtgt_1", messageId: "wcm_1", text: "Corrigido ")
        let delete = API.deleteWhatsappMessageForEveryone(chatId: "wtgt_1", messageId: "wcm_1")
        let star = API.starWhatsappMessage(chatId: "wtgt_1", messageId: "wcm_1", starred: false)

        XCTAssertEqual(edit.method, .patch)
        XCTAssertEqual(edit.path, "/whatsapp/conversations/wtgt_1/messages/wcm_1")
        XCTAssertEqual(try JSONSerialization.jsonObject(with: XCTUnwrap(edit.body)) as? NSDictionary, ["text": "Corrigido"])
        XCTAssertEqual(delete.method, .delete)
        XCTAssertEqual(delete.path, "/whatsapp/conversations/wtgt_1/messages/wcm_1")
        XCTAssertEqual(star.path, "/whatsapp/conversations/wtgt_1/messages/wcm_1/star")
        XCTAssertEqual(try JSONSerialization.jsonObject(with: XCTUnwrap(star.body)) as? NSDictionary, ["starred": false])
        XCTAssertEqual(API.whatsappMessageInfo(chatId: "wtgt_1", messageId: "wcm_1").path, "/whatsapp/conversations/wtgt_1/messages/wcm_1/info")
        XCTAssertEqual(API.searchWhatsappConversation(chatId: "wtgt_1", query: " pizza ").query, [URLQueryItem(name: "q", value: "pizza")])
        XCTAssertEqual(API.whatsappStarredMessages(chatId: "wtgt_1").path, "/whatsapp/conversations/wtgt_1/starred")
        XCTAssertEqual(API.whatsappGallery(chatId: "wtgt_1", kind: .documents, before: "wcm_9").query.map(\.value), ["documents", "wcm_9"])
    }

    func testTheMessageInfoTellsTheTimesOrThatAGroupDoesNot() throws {
        let info = try JSONCoding.decoder.decode(DataEnvelope<WhatsappMessageInfo>.self, from: Data("""
        {"data":{"friendly_id":"wcm_1","sent_at":"2026-10-06T12:00:00+00:00","receipt":"read",
          "delivered_at":"2026-10-06T12:00:05+00:00","read_at":"2026-10-06T12:03:00+00:00","played_at":null,"per_member":false}}
        """.utf8)).data
        let group = try JSONCoding.decoder.decode(WhatsappMessageInfo.self, from: Data(#"{"friendly_id":"wcm_2","sent_at":"2026-10-06T12:00:00+00:00","receipt":"bogus","delivered_at":null,"read_at":null,"played_at":null,"per_member":true}"#.utf8))

        XCTAssertEqual(info.receipt, .read)
        XCTAssertNotNil(info.readAt)
        XCTAssertNil(info.playedAt)
        XCTAssertFalse(info.perMember)
        XCTAssertTrue(group.perMember)
        XCTAssertNil(group.receipt)
    }

    func testTheNewKindsOfMessageGoWithTheirBodies() throws {
        let poll = API.sendWhatsappPoll(chatId: "wtgt_1", question: " Pizza? ", options: ["Sim", " ", "Não "], multiple: true, replyTo: "wcm_1")
        let location = API.sendWhatsappLocation(chatId: "wtgt_1", latitude: -23.5, longitude: -46.6, name: " ", address: "Av. Paulista")
        let contacts = API.sendWhatsappContacts(chatId: "wtgt_1", contactIds: ["wtgt_2", "wtgt_3"])

        XCTAssertEqual(poll.path, "/whatsapp/conversations/wtgt_1/poll")
        XCTAssertEqual(try JSONSerialization.jsonObject(with: XCTUnwrap(poll.body)) as? NSDictionary, ["question": "Pizza?", "options": ["Sim", "Não"], "multiple": true, "reply_to": "wcm_1"])
        XCTAssertEqual(location.path, "/whatsapp/conversations/wtgt_1/location")
        XCTAssertEqual(try JSONSerialization.jsonObject(with: XCTUnwrap(location.body)) as? NSDictionary, ["latitude": -23.5, "longitude": -46.6, "address": "Av. Paulista"])
        XCTAssertEqual(try JSONSerialization.jsonObject(with: XCTUnwrap(contacts.body)) as? NSDictionary, ["contacts": ["wtgt_2", "wtgt_3"]])
    }

    func testAStickerGoesAsAMultipartFormWithTheMessageItAnswers() throws {
        let file = MultipartForm.File(name: "file", fileName: "sticker.png", mimeType: "image/png", data: Data([0x01]))
        let endpoint = API.sendWhatsappSticker(chatId: "wtgt_1", file: file, replyTo: "wcm_1")
        let body = try XCTUnwrap(endpoint.body)
        let boundary = try XCTUnwrap(endpoint.contentType.components(separatedBy: "boundary=").last)

        XCTAssertEqual(endpoint.path, "/whatsapp/conversations/wtgt_1/sticker")

        var expected = Data("--\(boundary)\r\nContent-Disposition: form-data; name=\"reply_to\"\r\n\r\nwcm_1\r\n".utf8)
        expected.append(Data("--\(boundary)\r\nContent-Disposition: form-data; name=\"file\"; filename=\"sticker.png\"\r\nContent-Type: image/png\r\n\r\n".utf8))
        expected.append(Data([0x01]))
        expected.append(Data("\r\n--\(boundary)--\r\n".utf8))

        XCTAssertEqual(body, expected)
    }

    func testTheGalleryPageTellsWhetherThereIsMore() throws {
        let page = try JSONCoding.decoder.decode(WhatsappGalleryPage.self, from: Data(#"{"data":[{"friendly_id":"wcm_1","from_me":false,"type":"imageMessage","body":null,"sent_at":"2026-10-06T12:00:00+00:00","media":{"kind":"image"}}],"meta":{"has_more":true}}"#.utf8))

        XCTAssertEqual(page.data.map(\.friendlyId), ["wcm_1"])
        XCTAssertTrue(page.meta.hasMore)
    }

    func testAMentionIsTypedAfterAnAtAndWrittenAsTheMembersNumber() {
        let ana = WhatsappGroup.Member(jid: "5511911110000@s.whatsapp.net", name: "Ana Souza", number: "+5511911110000")
        let lid = WhatsappGroup.Member(jid: "123456@lid", name: "Bruno", number: nil)
        let me = WhatsappGroup.Member(jid: "5511900000000@s.whatsapp.net", name: "Eu", number: "+5511900000000", me: true)

        XCTAssertEqual(WhatsappMentions.query(in: "Oi @an"), "an")
        XCTAssertEqual(WhatsappMentions.query(in: "@"), "")
        XCTAssertNil(WhatsappMentions.query(in: "paulo@mail"))
        XCTAssertNil(WhatsappMentions.query(in: "Oi @ana tudo"))
        XCTAssertEqual(WhatsappMentions.suggestions(for: "an", in: [ana, lid, me]), [ana])
        XCTAssertEqual(WhatsappMentions.suggestions(for: "", in: [ana, lid, me]), [ana, lid])
        XCTAssertEqual(WhatsappMentions.suggestions(for: "1234", in: [ana, lid, me]), [lid])
        XCTAssertEqual(WhatsappMentions.inserting(ana, into: "Oi @an"), "Oi @5511911110000 ")
        XCTAssertEqual(WhatsappMentions.inserting(lid, into: "@"), "@123456 ")
    }

    func testTheGroupDecodesItsMembersAndWhoMayEditIt() throws {
        let group = try JSONCoding.decoder.decode(DataEnvelope<WhatsappGroup>.self, from: Data("""
        {"data":{"chat":{"friendly_id":"wtgt_1","name":"Família","is_group":true,"phone_number":null,"picture_url":null,
          "account":{"friendly_id":"wacc_1","nickname":"Personal"},"unread_count":0,"pinned":false,"priority":null,
          "priority_color":null,"tags":[],"last_message":null,"last_message_at":null},
          "admins_only":false,"info_admins_only":true,"is_admin":false,
          "members":[{"jid":"5511900000000@s.whatsapp.net","lid":null,"name":"Eu","number":"+5511900000000","admin":false,"me":true},
                     {"jid":"5511911110000@s.whatsapp.net","lid":"99@lid","name":"Ana","number":"+5511911110000","admin":true,"me":false}]}}
        """.utf8)).data

        XCTAssertFalse(group.canEditInfo)
        XCTAssertEqual(group.members.map(\.name), ["Eu", "Ana"])
        XCTAssertEqual(group.members.last?.lid, "99@lid")

        let changed = try JSONCoding.decoder.decode(WhatsappGroupMembersChange.self, from: Data(#"{"results":{"5511911110000@s.whatsapp.net":true,"5511922220000@s.whatsapp.net":false},"members":[]}"#.utf8))
        XCTAssertEqual(changed.refused, 1)
    }

    func testTheGroupToolsGoToTheirPathsWithTheirBodies() throws {
        let create = API.createWhatsappGroup(accountId: "wacc_1", subject: " Família ", memberIds: ["wtgt_2"], description: "  ")
        let update = API.updateWhatsappGroup(chatId: "wtgt_1", WhatsappGroupUpdate(adminsOnly: true, ephemeralSeconds: 86_400))
        let members = API.changeWhatsappGroupMembers(chatId: "wtgt_1", action: .promote, participants: ["5511911110000"])
        let invite = API.whatsappGroupInvite(chatId: "wtgt_1", revoke: true)

        XCTAssertEqual(create.path, "/whatsapp/groups")
        XCTAssertEqual(try JSONSerialization.jsonObject(with: XCTUnwrap(create.body)) as? NSDictionary, ["account": "wacc_1", "subject": "Família", "members": ["wtgt_2"]])
        XCTAssertEqual(update.method, .patch)
        XCTAssertEqual(update.path, "/whatsapp/conversations/wtgt_1/group")
        XCTAssertEqual(try JSONSerialization.jsonObject(with: XCTUnwrap(update.body)) as? NSDictionary, ["admins_only": true, "ephemeral_seconds": 86_400])
        XCTAssertEqual(members.path, "/whatsapp/conversations/wtgt_1/group/members")
        XCTAssertEqual(try JSONSerialization.jsonObject(with: XCTUnwrap(members.body)) as? NSDictionary, ["action": "promote", "participants": ["5511911110000"]])
        XCTAssertEqual(try JSONSerialization.jsonObject(with: XCTUnwrap(invite.body)) as? NSDictionary, ["revoke": true])
        XCTAssertEqual(API.leaveWhatsappGroup(chatId: "wtgt_1").path, "/whatsapp/conversations/wtgt_1/group/leave")
        XCTAssertTrue(API.updateWhatsappGroupPicture(chatId: "wtgt_1", file: .init(name: "file", fileName: "p.jpg", mimeType: "image/jpeg", data: Data())).contentType.hasPrefix("multipart/form-data"))
        XCTAssertEqual(try JSONCoding.decoder.decode(WhatsappGroupInvite.self, from: Data(#"{"url":"https://chat.whatsapp.com/abc"}"#.utf8)).url, "https://chat.whatsapp.com/abc")
    }
}
