import React, { useState, useRef, useEffect } from 'react';
import { Head } from '@inertiajs/react';
import Navigation from './Navigation';

interface Message {
  id: number;
  sender: 'customer' | 'shop_owner';
  content: string;
  timestamp: string;
  images?: string[];
}

interface Chat {
  id: number;
  name: string;
  avatar: string;
  online: boolean;
  role: string;
  lastMessage: string;
  lastMessageTime: string;
}

interface ShopOwner {
  id: number;
  name: string;
  avatar: string;
  online: boolean;
}

interface Props {
  shopOwner: ShopOwner;
}

const Message: React.FC<Props> = ({ shopOwner }) => {
  const [messages, setMessages] = useState<Message[]>([]);
  const [selectedChat, setSelectedChat] = useState<Chat | null>(null);
  const [chats, setChats] = useState<Chat[]>([
    {
      id: 1,
      name: 'Kaiya George',
      avatar: 'https://via.placeholder.com/48',
      online: true,
      role: 'Project Manager',
      lastMessage: 'Got it, thanks',
      lastMessageTime: '15 mins',
    },
    {
      id: 2,
      name: 'Lindsey Curtis',
      avatar: 'https://via.placeholder.com/48',
      online: true,
      role: 'Designer',
      lastMessage: 'Perfect timing',
      lastMessageTime: '30 mins',
    },
    {
      id: 3,
      name: 'Zain Geldt',
      avatar: 'https://via.placeholder.com/48',
      online: true,
      role: 'Content Writer',
      lastMessage: 'Let me check',
      lastMessageTime: '45 mins',
    },
    {
      id: 4,
      name: 'Carla George',
      avatar: 'https://via.placeholder.com/48',
      online: false,
      role: 'Front-end Developer',
      lastMessage: 'See you soon',
      lastMessageTime: '2 days',
    },
    {
      id: 5,
      name: 'Abram Schleifer',
      avatar: 'https://via.placeholder.com/48',
      online: true,
      role: 'Digital Marketer',
      lastMessage: 'All set!',
      lastMessageTime: '1 hour',
    },
  ]);

  const [searchQuery, setSearchQuery] = useState('');
  const [inputValue, setInputValue] = useState('');
  const [isTyping, setIsTyping] = useState(false);
  const [selectedImages, setSelectedImages] = useState<File[]>([]);
  const [fullscreenImage, setFullscreenImage] = useState<string | null>(null);
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  // Set first chat as selected by default
  useEffect(() => {
    if (chats.length > 0 && !selectedChat) {
      setSelectedChat(chats[0]);
    }
  }, []);

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  };

  useEffect(() => {
    scrollToBottom();
  }, [messages]);

  const filteredChats = chats.filter((chat) =>
    chat.name.toLowerCase().includes(searchQuery.toLowerCase())
  );

  const handleSendMessage = () => {
    if ((inputValue.trim() || selectedImages.length > 0) && selectedChat) {
      // Convert images to URLs (in real app, you'd upload to server first)
      const imageUrls = selectedImages.map((file) => URL.createObjectURL(file));

      const newMessage: Message = {
        id: messages.length + 1,
        sender: 'customer',
        content: inputValue,
        timestamp: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
        images: imageUrls.length > 0 ? imageUrls : undefined,
      };
      setMessages([...messages, newMessage]);
      setInputValue('');
      setSelectedImages([]);
      setIsTyping(false);
    }
  };

  const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = e.target.files;
    if (files) {
      const imageFiles = Array.from(files).filter((file) =>
        file.type.startsWith('image/')
      );

      if (imageFiles.length > 0) {
        setSelectedImages((prev) => [...prev, ...imageFiles]);
      }

      // Reset the input so the same file can be selected again
      if (fileInputRef.current) {
        fileInputRef.current.value = '';
      }
    }
  };

  const handleUploadClick = () => {
    fileInputRef.current?.click();
  };

  const removeImage = (index: number) => {
    setSelectedImages((prev) => prev.filter((_, i) => i !== index));
  };

  const handleKeyPress = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSendMessage();
    }
  };

  return (
    <div className="min-h-screen flex flex-col bg-gray-50">
      <Head title={`Chat with ${shopOwner.name}`} />
      <Navigation />

      <main className="flex-1 flex flex-col max-w-7xl mx-auto w-full">
        <div className="flex gap-4 h-[calc(100vh-120px)] m-4">
          
          {/* Chat List Sidebar */}
          <div className="w-80 bg-white rounded-2xl shadow-sm flex flex-col overflow-hidden">
            {/* Header */}
            <div className="border-b border-gray-200 px-6 py-4">
              <h1 className="text-2xl font-bold text-black mb-4">Solespace</h1>
              {/* Search Bar */}
              <div className="relative">
                <svg
                  className="absolute left-3 top-3 w-5 h-5 text-gray-400"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input
                  type="text"
                  placeholder="Search..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="w-full pl-10 pr-4 py-2 bg-gray-50 border border-gray-200 rounded-xl text-sm text-gray-900 placeholder-gray-400 outline-none focus:border-black transition-colors"
                />
              </div>
            </div>

            {/* Chats List */}
            <div className="flex-1 overflow-y-auto">
              {filteredChats.map((chat) => (
                <div
                  key={chat.id}
                  onClick={() => setSelectedChat(chat)}
                  className={`px-6 py-4 border-b border-gray-100 cursor-pointer transition-colors hover:bg-gray-50 ${
                    selectedChat?.id === chat.id ? 'bg-gray-50 border-l-4 border-l-blue-500' : ''
                  }`}
                >
                  <div className="flex items-start gap-3">
                    {/* Avatar */}
                    <div className="relative flex-shrink-0">
                      <div className="w-12 h-12 rounded-full bg-gray-200 overflow-hidden">
                        <img
                          src={chat.avatar}
                          alt={chat.name}
                          className="w-full h-full object-cover"
                        />
                      </div>
                      {chat.online && (
                        <div className="absolute bottom-0 right-0 w-3 h-3 bg-green-500 rounded-full border-2 border-white"></div>
                      )}
                    </div>

                    {/* Chat Info */}
                    <div className="flex-1 min-w-0">
                      <div className="flex items-baseline justify-between">
                        <h3 className="font-semibold text-black text-sm">{chat.name}</h3>
                        <span className="text-xs text-gray-500 ml-2">{chat.lastMessageTime}</span>
                      </div>
                      <p className="text-xs text-gray-500">{chat.role}</p>
                      <p className="text-xs text-gray-600 truncate mt-1">{chat.lastMessage}</p>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Message Container */}
          {selectedChat && (
          <div className="flex-1 flex flex-col bg-white rounded-2xl shadow-sm overflow-hidden">
          
          {/* Header */}
          <div className="border-b border-gray-200 px-6 py-4 flex items-center justify-between bg-white">
            <div className="flex items-center gap-4">
              {/* User Avatar */}
              <div className="relative">
                <div className="w-12 h-12 rounded-full bg-gray-200 overflow-hidden">
                  <img
                    src={selectedChat.avatar || 'https://via.placeholder.com/48'}
                    alt={selectedChat.name}
                    className="w-full h-full object-cover"
                  />
                </div>
                {/* Online Status */}
                {selectedChat.online && (
                  <div className="absolute bottom-0 right-0 w-3.5 h-3.5 bg-green-500 rounded-full border-2 border-white"></div>
                )}
              </div>

              {/* User Info */}
              <div>
                <h2 className="text-lg font-bold text-black">{selectedChat.name}</h2>
                <p className="text-xs text-gray-500">
                  {selectedChat.online ? 'Active now' : 'Offline'}
                </p>
              </div>
            </div>
          </div>

          {/* Chat Messages Area */}
          <div className="flex-1 overflow-y-auto px-6 py-6 space-y-4 bg-white">
            {messages.map((message) => (
              <div
                key={message.id}
                className={`flex flex-col ${message.sender === 'customer' ? 'items-end' : 'items-start'}`}
              >
                {message.images && message.images.length > 0 ? (
                  <div>
                    <div className="flex flex-wrap gap-2 mb-2">
                      {message.images.map((img, idx) => (
                        <img
                          key={idx}
                          src={img}
                          alt={`Attachment ${idx + 1}`}
                          className="rounded-lg max-w-[150px] max-h-[150px] object-cover cursor-pointer hover:opacity-80 transition-opacity"
                          onClick={() => setFullscreenImage(img)}
                        />
                      ))}
                    </div>
                    {message.content && (
                      <div
                        className={`${
                          message.sender === 'customer'
                            ? 'bg-blue-500 text-white px-4 py-2 text-sm rounded-lg shadow-sm'
                            : 'bg-gray-100 text-gray-900 px-4 py-2 text-sm rounded-lg shadow-sm'
                        }`}
                      >
                        <p className="break-words text-sm leading-relaxed">
                          {message.content}
                        </p>
                      </div>
                    )}
                  </div>
                ) : (
                  <div
                    className={`max-w-xs lg:max-w-md xl:max-w-lg ${
                      message.sender === 'customer'
                        ? 'bg-blue-500 text-white inline-block px-4 py-2 text-sm rounded-lg shadow-sm'
                        : 'bg-gray-100 text-gray-900 inline-block px-4 py-2 text-sm rounded-lg shadow-sm'
                    }`}
                  >
                    <p className="break-words text-sm leading-relaxed">
                      {message.content}
                    </p>
                  </div>
                )}

                {/* Timestamp outside the bubble */}
                <div className={`mt-2 ${message.sender === 'customer' ? 'text-right' : 'text-left'}`}>
                  <span className={`text-xs ${message.sender === 'customer' ? 'text-gray-400' : 'text-gray-500'}`} style={{ fontSize: '11px' }}>
                    {message.timestamp}
                  </span>
                </div>
              </div>
            ))}
            <div ref={messagesEndRef} />
          </div>

          {/* Input Area */}
          <div className="border-t border-gray-200 bg-white px-6 py-4 rounded-b-2xl">
            {/* Image Previews */}
            {selectedImages.length > 0 && (
              <div className="mb-3 flex flex-wrap gap-2">
                {selectedImages.map((file, index) => (
                  <div key={index} className="relative group">
                    <img
                      src={URL.createObjectURL(file)}
                      alt={`Preview ${index + 1}`}
                      className="w-20 h-20 object-cover rounded-lg border border-gray-200"
                    />
                    <button
                      onClick={() => removeImage(index)}
                      className="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity"
                      title="Remove image"
                    >
                      ×
                    </button>
                  </div>
                ))}
              </div>
            )}

            <div className="flex items-center gap-3">
              <input
                type="file"
                ref={fileInputRef}
                onChange={handleFileUpload}
                accept="image/*"
                multiple
                className="hidden"
              />
              <button 
                onClick={handleUploadClick}
                className="p-2 hover:bg-gray-100 rounded-full transition-colors flex-shrink-0"
                title="Upload images"
              >
                <svg className="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 5v14m7-7H5" />
                </svg>
              </button>

              {/* Message Input */}
              <div className="flex-1 bg-gray-50 border border-gray-200 rounded-2xl px-4 py-3 flex items-center">
                <input
                  type="text"
                  value={inputValue}
                  onChange={(e) => {
                    setInputValue(e.target.value);
                    setIsTyping(e.target.value.length > 0);
                  }}
                  onKeyPress={handleKeyPress}
                  placeholder="Type a message"
                  className="bg-transparent flex-1 text-sm text-gray-900 placeholder-gray-400 outline-none"
                />
              </div>

              {/* Send Button */}
              <button
                onClick={handleSendMessage}
                disabled={!inputValue.trim() && selectedImages.length === 0}
                className={`p-2 rounded-full transition-colors flex-shrink-0 ${
                  inputValue.trim() || selectedImages.length > 0
                    ? 'text-gray-600 hover:text-gray-800'
                    : 'text-gray-300 cursor-not-allowed'
                }`}
              >
                <svg
                  className="w-5 h-5"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path d="M16.6915026,12.4744748 L3.50612381,13.2599618 C3.19218622,13.2599618 3.03521743,13.4170592 3.03521743,13.5741566 L1.15159189,20.0151496 C0.8376543,20.8006365 0.99,21.89 1.77946707,22.52 C2.41,22.99 3.50612381,23.1 4.13399899,22.8429026 L21.714504,14.0454487 C22.6563168,13.5741566 23.1272231,12.6315722 22.9702544,11.6889879 L4.13399899,1.16640469 C3.34915502,0.9015399 2.40734225,0.9015399 1.77946707,1.4742449 C0.994623095,2.0469499 0.837654326,3.1368016 1.15159189,3.9222884 L3.03521743,10.3632814 C3.03521743,10.5203788 3.19218622,10.6774762 3.50612381,10.6774762 L16.6915026,11.4629631 C16.6915026,11.4629631 17.1624089,11.4629631 17.1624089,12.0356681 C17.1624089,12.4744748 16.6915026,12.4744748 16.6915026,12.4744748 Z" />
                </svg>
              </button>
            </div>
          </div>
          </div>
          )}
        </div>
      </main>

      {/* Fullscreen Image Modal */}
      {fullscreenImage && (
        <div 
          className="fixed inset-0 backdrop-blur-md flex items-center justify-center z-50"
          onClick={() => setFullscreenImage(null)}
        >
          <button
            onClick={() => setFullscreenImage(null)}
            className="absolute top-6 right-6 bg-white rounded-full w-10 h-10 flex items-center justify-center hover:bg-gray-100 transition-colors shadow-lg"
            title="Close"
          >
            <svg className="w-6 h-6 text-gray-800" fill="currentColor" viewBox="0 0 24 24">
              <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z" />
            </svg>
          </button>
          <img
            src={fullscreenImage}
            alt="Fullscreen view"
            className="max-w-[90vw] max-h-[90vh] object-contain"
          />
        </div>
      )}
    </div>
  );
};

export default Message;
