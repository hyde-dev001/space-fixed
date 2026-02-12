import { Head } from "@inertiajs/react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { useState } from "react";

interface Message {
  id: number;
  sender: string;
  content: string;
  timestamp: string;
  type: "inquiry" | "repair" | "cancellation" | "refund";
}

export default function CustomerSupport() {
  const [messages, setMessages] = useState<Message[]>([
    {
      id: 1,
      sender: "John Doe",
      content: "I have a question about my recent order #12345",
      timestamp: "2025-02-12 10:30 AM",
      type: "inquiry",
    },
    {
      id: 2,
      sender: "Jane Smith",
      content: "I would like to request a repair for my product",
      timestamp: "2025-02-12 09:15 AM",
      type: "repair",
    },
  ]);

  const [newMessage, setNewMessage] = useState("");
  const [messageType, setMessageType] = useState<"inquiry" | "repair" | "cancellation" | "refund">("inquiry");

  const handleSendMessage = () => {
    if (newMessage.trim()) {
      const message: Message = {
        id: messages.length + 1,
        sender: "Shop Owner",
        content: newMessage,
        timestamp: new Date().toLocaleString(),
        type: messageType,
      };
      setMessages([...messages, message]);
      setNewMessage("");
    }
  };

  const getTypeIcon = (type: string) => {
    switch (type) {
      case "inquiry":
        return "❓";
      case "repair":
        return "🔧";
      case "cancellation":
        return "❌";
      case "refund":
        return "💰";
      default:
        return "💬";
    }
  };

  const getTypeBgColor = (type: string) => {
    switch (type) {
      case "inquiry":
        return "bg-blue-100 text-blue-700";
      case "repair":
        return "bg-orange-100 text-orange-700";
      case "cancellation":
        return "bg-red-100 text-red-700";
      case "refund":
        return "bg-green-100 text-green-700";
      default:
        return "bg-gray-100 text-gray-700";
    }
  };

  return (
    <AppLayoutERP>
      <Head title="Customer Support - Solespace ERP" />
      <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-8 shadow-sm">
        <h2 className="text-2xl font-semibold text-gray-900 dark:text-white mb-4">Customer Support</h2>
        <p className="text-gray-600 dark:text-gray-400 mb-6">Manage customer inquiries regarding orders, repairs, cancellations, and refunds</p>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          {/* Messages List */}
          <div className="lg:col-span-2">
            <div className="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 h-96 overflow-y-auto mb-4">
              {messages.length === 0 ? (
                <p className="text-gray-500 dark:text-gray-400 text-center py-8">No messages yet</p>
              ) : (
                <div className="space-y-3">
                  {messages.map((msg) => (
                    <div
                      key={msg.id}
                      className="bg-white dark:bg-gray-700 p-4 rounded-lg border-l-4 border-blue-500"
                    >
                      <div className="flex items-start justify-between">
                        <div className="flex-1">
                          <div className="flex items-center gap-2 mb-2">
                            <span className={`px-3 py-1 rounded-full text-sm font-medium ${getTypeBgColor(msg.type)}`}>
                              {getTypeIcon(msg.type)} {msg.type.charAt(0).toUpperCase() + msg.type.slice(1)}
                            </span>
                          </div>
                          <p className="font-semibold text-gray-900 dark:text-white">{msg.sender}</p>
                          <p className="text-gray-700 dark:text-gray-300 mt-2">{msg.content}</p>
                        </div>
                      </div>
                      <p className="text-xs text-gray-500 dark:text-gray-400 mt-2">{msg.timestamp}</p>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {/* Message Input */}
            <div className="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
              <div className="mb-4">
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                  Message Type
                </label>
                <select
                  value={messageType}
                  onChange={(e) => setMessageType(e.target.value as any)}
                  className="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white"
                >
                  <option value="inquiry">Customer Inquiry</option>
                  <option value="repair">Repair Request</option>
                  <option value="cancellation">Cancellation Request</option>
                  <option value="refund">Refund Request</option>
                </select>
              </div>

              <div className="flex gap-2">
                <textarea
                  value={newMessage}
                  onChange={(e) => setNewMessage(e.target.value)}
                  placeholder="Type your response here..."
                  className="flex-1 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white"
                  rows={3}
                />
                <button
                  onClick={handleSendMessage}
                  className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors"
                >
                  Send
                </button>
              </div>
            </div>
          </div>

          {/* Statistics */}
          <div className="lg:col-span-1">
            <div className="bg-gray-50 dark:bg-gray-800 rounded-lg p-6 space-y-4">
              <h3 className="text-lg font-semibold text-gray-900 dark:text-white">Support Statistics</h3>

              <div className="bg-blue-100 dark:bg-blue-900 p-4 rounded-lg">
                <p className="text-sm text-gray-600 dark:text-gray-400">Total Inquiries</p>
                <p className="text-2xl font-bold text-blue-600 dark:text-blue-400">
                  {messages.filter((m) => m.type === "inquiry").length}
                </p>
              </div>

              <div className="bg-orange-100 dark:bg-orange-900 p-4 rounded-lg">
                <p className="text-sm text-gray-600 dark:text-gray-400">Repair Requests</p>
                <p className="text-2xl font-bold text-orange-600 dark:text-orange-400">
                  {messages.filter((m) => m.type === "repair").length}
                </p>
              </div>

              <div className="bg-red-100 dark:bg-red-900 p-4 rounded-lg">
                <p className="text-sm text-gray-600 dark:text-gray-400">Cancellations</p>
                <p className="text-2xl font-bold text-red-600 dark:text-red-400">
                  {messages.filter((m) => m.type === "cancellation").length}
                </p>
              </div>

              <div className="bg-green-100 dark:bg-green-900 p-4 rounded-lg">
                <p className="text-sm text-gray-600 dark:text-gray-400">Refund Requests</p>
                <p className="text-2xl font-bold text-green-600 dark:text-green-400">
                  {messages.filter((m) => m.type === "refund").length}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </AppLayoutERP>
  );
}
