import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Head, usePage, router } from '@inertiajs/react';
import Navigation from './Navigation';

interface ConversationMessage {
  id: number;
  conversation_id: number;
  sender_id: number;
  sender_type: string;
  content: string;
  attachments?: string[];
  created_at: string;
}

interface Conversation {
  id: number;
  shop_owner_id: number;
  customer_id: number;
  status: string;
  last_message_at: string;
  type?: 'repair' | 'product' | 'general';
  shopOwner?: {
    id: number;
    business_name: string;
    location?: string;
    business_address?: string;
    profile_photo?: string;
    email?: string;
    phone?: string;
  };
  repairRequest?: {
    request_id: string;
    repair_type: string;
    description: string;
    status: string;
    shoe_type?: string;
    brand?: string;
    total?: number;
    delivery_method?: string;
    scheduled_dropoff_date?: string;
    payment_status?: string;
  };
  messages?: ConversationMessage[];
}

interface Props {
  conversation?: Conversation | null;
  shopOwnerId?: number;
}

interface OrderProductPreview {
  name: string;
  quantity: string;
  unitPrice?: string;
}

const Message: React.FC<Props> = ({ conversation: initialConversation = null, shopOwnerId }) => {
  const page = usePage();
  const { auth } = page.props as any;
  
  // Check if user is still authenticated
  useEffect(() => {
    if (!auth?.user) {
      router.visit('/user/login');
    }
  }, [auth?.user]);

  if (!auth?.user) {
    return null;
  }

  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [selectedConversation, setSelectedConversation] = useState<Conversation | null>(initialConversation || null);
  const [messages, setMessages] = useState<ConversationMessage[]>([]);
  const [searchQuery, setSearchQuery] = useState('');
  const [activeFilter, setActiveFilter] = useState<'all' | 'repairs' | 'products' | 'general'>('all');
  const [inputValue, setInputValue] = useState('');
  const [selectedImages, setSelectedImages] = useState<File[]>([]);
  const [fullscreenImage, setFullscreenImage] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isSending, setIsSending] = useState(false);
  
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const messagesContainerRef = useRef<HTMLDivElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const pollingIntervalRef = useRef<NodeJS.Timeout | null>(null);

  // Fetch all conversations on mount
  useEffect(() => {
    const fetchConversations = async () => {
      console.log('=== Starting to fetch conversations ===');
      console.log('Auth user:', auth?.user);
      
      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        console.log('CSRF Token:', csrfToken ? 'Present' : 'Missing');
        
        const url = activeFilter === 'all' 
          ? '/api/customer/conversations'
          : `/api/customer/conversations?type=${activeFilter}`;
        
        const response = await fetch(url, {
          method: 'GET',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'include',
        });
        
        console.log('Response status:', response.status);
        console.log('Response headers:', Object.fromEntries(response.headers.entries()));
        
        if (!response.ok) {
          const errorText = await response.text();
          console.error('Failed to fetch conversations:', response.status, response.statusText, errorText);
          if (response.status === 401) {
            console.log('Unauthorized - redirecting to login');
            router.visit('/user/login');
          }
          setIsLoading(false);
          return;
        }
        
        const data = await response.json();
        console.log('=== Raw API Response ===');
        console.log('Data:', data);
        console.log('Data type:', Array.isArray(data) ? 'Array' : typeof data);
        console.log('Data length:', Array.isArray(data) ? data.length : 'N/A');
        
        // Handle both array and object with data property
        const conversationsList = Array.isArray(data) ? data : (data.data || []);
        setConversations(conversationsList);
        
        // If no initial conversation but shopOwnerId was provided, create/fetch that conversation
        if (!selectedConversation && shopOwnerId) {
          const existingConv = conversationsList.find((c: Conversation) => c.shop_owner_id === shopOwnerId);
          if (existingConv) {
            console.log('Found existing conversation for shop owner:', existingConv);
            setSelectedConversation(existingConv);
            fetchMessages(existingConv.id);
          } else {
            console.log('Creating new conversation for shop owner:', shopOwnerId);
            // Create new conversation
            createConversation(shopOwnerId);
          }
        } else if (initialConversation && conversationsList.length > 0) {
          // If initial conversation was provided, try to find it in the fetched list
          const foundConv = conversationsList.find((c: Conversation) => c.id === initialConversation.id);
          if (foundConv) {
            console.log('Found initial conversation in list:', foundConv);
            setSelectedConversation(foundConv);
            fetchMessages(foundConv.id);
          } else {
            console.log('Initial conversation not found, using it anyway');
            fetchMessages(initialConversation.id);
          }
        } else if (conversationsList.length > 0) {
          // Auto-select first conversation if no specific one was requested
          console.log('Auto-selecting first conversation:', conversationsList[0]);
          setSelectedConversation(conversationsList[0]);
          fetchMessages(conversationsList[0].id);
        } else {
          console.log('No conversations available');
        }
      } catch (error) {
        console.error('Failed to fetch conversations:', error);
      } finally {
        setIsLoading(false);
      }
    };

    fetchConversations();
    
    // Auto-refresh conversations every 2 seconds
    const conversationInterval = setInterval(() => {
      fetchConversations();
    }, 2000);
    
    return () => clearInterval(conversationInterval);
  }, [activeFilter]);  // Re-fetch when filter changes

  const fetchMessages = async (conversationId: number, silent: boolean = false) => {
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const response = await fetch(`/api/customer/conversations/${conversationId}/messages`, {
        method: 'GET',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
      });
      
      if (!response.ok) {
        if (!silent) {
          console.error('Failed to fetch messages:', response.status, response.statusText);
        }
        if (response.status === 401) {
          router.visit('/user/login');
        }
        return;
      }
      
      const data = await response.json();
      if (!silent) {
        console.log('Fetched messages for conversation', conversationId, ':', data);
      }
      
      // Handle both array and object with data property
      const messagesList = Array.isArray(data) ? data : (data.data || []);
      setMessages(messagesList);
    } catch (error) {
      if (!silent) {
        console.error('Failed to fetch messages:', error);
      }
    }
  };

  const createConversation = async (shopOwnerId: number) => {
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const response = await fetch('/api/customer/conversations/get-or-create', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
        body: JSON.stringify({ shop_owner_id: shopOwnerId }),
      });
      
      if (!response.ok) {
        console.error('Failed to create conversation:', response.status, response.statusText);
        if (response.status === 401) {
          router.visit('/user/login');
        }
        return;
      }
      
      const conversation = await response.json();
      
      // Format the conversation data to match the expected structure
      const formattedConversation = {
        ...conversation,
        shopOwner: conversation.shopOwner || conversation.shop_owner ? {
          id: conversation.shopOwner?.id || conversation.shop_owner?.id,
          business_name: conversation.shopOwner?.business_name || conversation.shop_owner?.business_name || 'Unknown Shop',
          location: conversation.shopOwner?.business_address || conversation.shop_owner?.business_address || conversation.shopOwner?.location || conversation.shop_owner?.location || '',
          profile_photo: conversation.shopOwner?.profile_photo || conversation.shop_owner?.profile_photo || '',
          email: conversation.shopOwner?.email || conversation.shop_owner?.email || '',
          phone: conversation.shopOwner?.phone || conversation.shop_owner?.phone || '',
        } : undefined,
      };
      
      setConversations((prev) => [formattedConversation, ...prev]);
      setSelectedConversation(formattedConversation);
      setMessages([]);
    } catch (error) {
      console.error('Failed to create conversation:', error);
    }
  };

  const handleSendMessage = async () => {
    if (!selectedConversation) return;
    if (!inputValue.trim() && selectedImages.length === 0) return;

    setIsSending(true);
    try {
      const formData = new FormData();
      if (inputValue.trim()) {
        formData.append('content', inputValue);
      }
      
      selectedImages.forEach((image) => {
        formData.append('images[]', image);
      });

      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const response = await fetch(`/api/customer/conversations/${selectedConversation.id}/messages`, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
        body: formData,
      });

      if (!response.ok) {
        console.error('Failed to send message:', response.status, response.statusText);
        if (response.status === 401) {
          router.visit('/user/login');
        }
        return;
      }

      const data = await response.json();
      const newMessage = data.data || data;
      setMessages((prev) => [...prev, newMessage]);
      setInputValue('');
      setSelectedImages([]);
    } catch (error) {
      console.error('Failed to send message:', error);
    } finally {
      setIsSending(false);
    }
  };

  const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = e.target.files;
    if (!files) return;

    const imageFiles = Array.from(files).filter((file) => file.type.startsWith('image/'));
    if (imageFiles.length > 0) {
      setSelectedImages((prev) => [...prev, ...imageFiles]);
    }

    if (fileInputRef.current) {
      fileInputRef.current.value = '';
    }
  };

  const handleUploadClick = () => {
    fileInputRef.current?.click();
  };

  const removeImage = (index: number) => {
    setSelectedImages((prev) => prev.filter((_, i) => i !== index));
  };

  const handleKeyPress = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSendMessage();
    }
  };

  const handleConversationClick = (conversation: Conversation) => {
    setSelectedConversation(conversation);
    fetchMessages(conversation.id);
  };

  const filteredConversations = conversations.filter((conv) => {
    const shopName = conv.shopOwner?.business_name || 'Unknown Shop';
    const location = conv.shopOwner?.location || '';
    return `${shopName} ${location}`.toLowerCase().includes(searchQuery.toLowerCase());
  });

  const parseOrderProducts = (content: string, itemsSummary: string): OrderProductPreview[] => {
    const productsBlockMatch = content.match(/\*\*Products:\*\*\s*([\s\S]*?)(?=\n\*\*|$)/);

    if (productsBlockMatch?.[1]) {
      return productsBlockMatch[1]
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line.startsWith('-'))
        .map((line) => {
          const cleanedLine = line.replace(/^-\s*/, '').trim();
          const productMatch = cleanedLine.match(/^(.*?)\s+x(\d+)(?:\s+\(₱?([\d,]+(?:\.\d{1,2})?)\))?$/);

          if (!productMatch) {
            return {
              name: cleanedLine,
              quantity: '1',
            };
          }

          return {
            name: productMatch[1].trim(),
            quantity: productMatch[2].trim(),
            unitPrice: productMatch[3]?.trim(),
          };
        });
    }

    if (!itemsSummary) return [];

    return itemsSummary
      .split(',')
      .map((item) => item.trim())
      .filter(Boolean)
      .map((item) => {
        const summaryMatch = item.match(/^(.*?)\s+x(\d+)$/);
        if (!summaryMatch) {
          return {
            name: item,
            quantity: '1',
          };
        }
        return {
          name: summaryMatch[1].trim(),
          quantity: summaryMatch[2].trim(),
        };
      });
  };

  const resolveAttachmentUrl = (path: string) => {
    if (!path) return path;
    if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('data:') || path.startsWith('blob:')) {
      return path;
    }
    if (path.startsWith('/')) {
      return path;
    }
    if (path.startsWith('storage/')) {
      return `/${path}`;
    }
    return `/storage/${path}`;
  };

  // Set up polling for real-time message updates
  useEffect(() => {
    // Clear any existing interval
    if (pollingIntervalRef.current) {
      clearInterval(pollingIntervalRef.current);
      pollingIntervalRef.current = null;
    }

    // Only poll if a conversation is selected
    if (selectedConversation) {
      // Poll every 2 seconds for new messages
      pollingIntervalRef.current = setInterval(() => {
        fetchMessages(selectedConversation.id, true); // silent = true to avoid console spam
      }, 2000);
    }

    // Cleanup on unmount or when conversation changes
    return () => {
      if (pollingIntervalRef.current) {
        clearInterval(pollingIntervalRef.current);
        pollingIntervalRef.current = null;
      }
    };
  }, [selectedConversation]);

  return (
    <div className="min-h-screen flex flex-col bg-gray-50">
      <Head title="Messages - Solespace" />
      <Navigation />

      <main className="flex-1 flex flex-col w-full">
        <div className="flex gap-2 h-[calc(100vh-120px)] m-4">
          <div className="w-96 bg-white rounded-2xl shadow-sm flex flex-col overflow-hidden">
            <div className="border-b border-gray-200 px-6 py-4">
              <h1 className="text-2xl font-bold text-black mb-4">Shop Messages</h1>
              <div className="relative mb-4">
                <svg
                  className="absolute left-3 top-3 w-5 h-5 text-gray-400"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth={2}
                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                  />
                </svg>
                <input
                  type="text"
                  placeholder="Search shops..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="w-full pl-10 pr-4 py-2 bg-gray-50 border border-gray-200 rounded-xl text-sm text-gray-900 placeholder-gray-400 outline-none focus:border-black transition-colors"
                />
              </div>
              
              {/* Filter Tabs */}
              <div className="flex gap-2">
                <button
                  onClick={() => setActiveFilter('all')}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    activeFilter === 'all'
                      ? 'bg-black text-white'
                      : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                  }`}
                >
                  All
                </button>
                <button
                  onClick={() => setActiveFilter('repairs')}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    activeFilter === 'repairs'
                      ? 'bg-black text-white'
                      : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                  }`}
                >
                  Repairs
                </button>
                <button
                  onClick={() => setActiveFilter('products')}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    activeFilter === 'products'
                      ? 'bg-black text-white'
                      : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                  }`}
                >
                  Products
                </button>
                <button
                  onClick={() => setActiveFilter('general')}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    activeFilter === 'general'
                      ? 'bg-black text-white'
                      : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                  }`}
                >
                  General
                </button>
              </div>
            </div>

            <div className="flex-1 overflow-y-auto">
              {isLoading ? (
                <div className="flex items-center justify-center h-full text-gray-500">
                  <span>Loading conversations...</span>
                </div>
              ) : filteredConversations.length === 0 ? (
                <div className="flex items-center justify-center h-full text-gray-500">
                  <span>No conversations yet</span>
                </div>
              ) : (
                filteredConversations.map((conversation) => (
                  <div
                    key={conversation.id}
                    onClick={() => handleConversationClick(conversation)}
                    className={`px-6 py-4 border-b border-gray-100 cursor-pointer transition-colors hover:bg-gray-50 ${
                      selectedConversation?.id === conversation.id ? 'bg-gray-50 border-l-4 border-l-blue-500' : ''
                    }`}
                  >
                    <div className="flex items-start gap-3">
                      <div className="relative flex-shrink-0">
                        {conversation.shopOwner?.profile_photo ? (
                          <img
                            src={`/storage/${conversation.shopOwner.profile_photo}`}
                            alt={conversation.shopOwner.business_name}
                            className="w-12 h-12 rounded-full object-cover"
                          />
                        ) : (
                          <div className="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                            <svg className="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth={2}
                                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"
                              />
                            </svg>
                          </div>
                        )}
                      </div>

                      <div className="flex-1 min-w-0">
                        <div className="flex items-baseline justify-between">
                          <h3 className="font-semibold text-black text-sm truncate">
                            {conversation.repairRequest 
                              ? `${conversation.shopOwner?.business_name || 'Shop'} - ${conversation.repairRequest.request_id}`
                              : conversation.shopOwner?.business_name || 'Unknown Shop'
                            }
                          </h3>
                          <span className="text-xs text-gray-500 ml-2 flex-shrink-0">
                            {conversation.last_message_at ? new Date(conversation.last_message_at).toLocaleDateString() : 'No messages'}
                          </span>
                        </div>
                        <p className="text-xs text-gray-500 truncate">
                          {conversation.repairRequest?.repair_type || conversation.shopOwner?.business_address || 'No details'}
                        </p>
                      </div>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>

          <div className="flex-1 flex flex-col bg-white rounded-2xl shadow-sm overflow-hidden">
            <div className="border-b border-gray-200 px-6 py-4 flex items-center justify-between bg-white">
              {selectedConversation ? (
                <div className="flex items-center gap-4">
                  <div className="relative">
                    {selectedConversation.shopOwner?.profile_photo ? (
                      <img
                        src={`/storage/${selectedConversation.shopOwner.profile_photo}`}
                        alt={selectedConversation.shopOwner.business_name}
                        className="w-12 h-12 rounded-full object-cover"
                      />
                    ) : (
                      <div className="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                        <svg className="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={2}
                            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"
                          />
                        </svg>
                      </div>
                    )}
                  </div>
                  <div>
                    <h2 className="text-lg font-bold text-black">
                      {selectedConversation.shopOwner?.business_name || 'Unknown Shop'}
                    </h2>
                    <p className="text-xs text-gray-500">
                      {selectedConversation.repairRequest?.repair_type || 'General'}
                    </p>
                  </div>
                </div>
              ) : (
                <div className="text-sm text-gray-500">Select a shop to start chatting</div>
              )}
            </div>

            <div 
              ref={messagesContainerRef}
              className="flex-1 overflow-y-auto px-6 py-6 space-y-4 bg-white"
            >
              {selectedConversation && messages.length === 0 ? (
                <div className="flex flex-col items-center justify-center h-full text-gray-500 space-y-3">
                  <svg className="w-16 h-16 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      strokeWidth={2}
                      d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
                    />
                  </svg>
                  <p className="text-lg font-medium">Start a conversation with {selectedConversation.shopOwner?.business_name || 'this shop'}</p>
                  <p className="text-sm text-gray-400">Ask about repairs, products, or order status</p>
                </div>
              ) : (
                messages.map((message) => {
                  // System messages (order notifications)
                  if (message.sender_type === 'system') {
                    // Parse message content to extract details
                    const content = message.content || '';
                    const isRepairMessage = content.includes('Repair') || content.includes('Type:**');
                    const isOrderMessage = content.includes('Order') && (content.includes('Order Number:') || content.includes('New Order Placed'));
                    
                    // Extract repair details from message
                    const typeMatch = content.match(/\*\*Type:\*\*\s*(.+?)(?=\*\*|$)/);
                    const deliveryMatch = content.match(/\*\*Delivery:\*\*\s*(.+?)(?=\*\*|$)/);
                    const statusMatch = content.match(/\*\*Status:\*\*\s*(.+?)(?=\*\*|$)/);
                    
                    const repairType = typeMatch ? typeMatch[1].trim() : 'Repair';
                    const deliveryType = deliveryMatch ? deliveryMatch[1].trim() : '';
                    const status = statusMatch ? statusMatch[1].trim() : '';
                    
                    // Extract order details from message
                    const orderNumberMatch = content.match(/\*\*Order Number:\*\*\s*(.+?)(?=\n|$)/);
                    const itemsMatch = content.match(/\*\*Items:\*\*\s*(.+?)(?=\n|$)/);
                    const totalMatch = content.match(/\*\*Total:\*\*\s*₱?(.+?)(?=\n|$)/);
                    const orderStatusMatch = content.match(/\*\*Status:\*\*\s*(.+?)(?=\n|$)/);
                    
                    const orderNumber = orderNumberMatch ? orderNumberMatch[1].trim() : '';
                    const items = itemsMatch ? itemsMatch[1].trim() : '';
                    const total = totalMatch ? totalMatch[1].trim() : '';
                    const orderStatus = orderStatusMatch ? orderStatusMatch[1].trim() : '';
                    const orderProducts = parseOrderProducts(content, items);
                    const orderProductImages = (message.attachments || []).filter((attachment): attachment is string => typeof attachment === 'string' && attachment.length > 0);
                    
                    // Determine status badge color
                    const getStatusColor = (status: string) => {
                      const lowerStatus = status.toLowerCase();
                      if (lowerStatus.includes('accepted') || lowerStatus.includes('approved')) return 'bg-green-100 text-green-800';
                      if (lowerStatus.includes('progress') || lowerStatus.includes('processing') || lowerStatus.includes('working')) return 'bg-blue-100 text-blue-800';
                      if (lowerStatus.includes('completed') || lowerStatus.includes('done')) return 'bg-purple-100 text-purple-800';
                      if (lowerStatus.includes('rejected') || lowerStatus.includes('cancelled')) return 'bg-red-100 text-red-800';
                      if (lowerStatus.includes('waiting') || lowerStatus.includes('pending')) return 'bg-yellow-100 text-yellow-800';
                      return 'bg-gray-100 text-gray-800';
                    };
                    
                    // Order Message Card
                    if (isOrderMessage) {
                      const shopInfo = selectedConversation?.shopOwner;
                      
                      return (
                        <div key={message.id} className="flex justify-center my-6">
                          <div className="bg-white border border-gray-200 rounded-xl p-5 max-w-lg w-full shadow-sm hover:shadow-md transition-shadow">
                            {/* Header with Title and Status Badge */}
                            <div className="flex items-start justify-between mb-4">
                              <div className="flex-1">
                                <h3 className="text-base font-semibold text-gray-900 mb-1 flex items-center gap-2">
                                  <span>New Order Placed</span>
                                </h3>
                                <p className="text-xs text-gray-500">
                                  {new Date(message.created_at).toLocaleString('en-US', {
                                    month: 'short',
                                    day: 'numeric',
                                    year: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit'
                                  })}
                                </p>
                              </div>
                              {orderStatus && (
                                <span className={`text-xs font-semibold px-2.5 py-1 rounded-full ${getStatusColor(orderStatus)}`}>
                                  {orderStatus}
                                </span>
                              )}
                            </div>
                            
                            {/* Order Details Section */}
                            <div className="bg-gray-50 rounded-lg p-4 mb-4 space-y-3">
                              {orderProducts.length > 0 && (
                                <div className="space-y-2">
                                  <span className="text-gray-500 text-xs font-medium">Products:</span>
                                  <div className="space-y-2">
                                    {orderProducts.map((product, productIndex) => {
                                      const productImage = orderProductImages[productIndex];

                                      return (
                                        <div key={`${product.name}-${productIndex}`} className="flex items-center gap-3 bg-white rounded-lg p-2 border border-gray-100">
                                          {productImage ? (
                                            <img
                                              src={resolveAttachmentUrl(productImage)}
                                              alt={product.name}
                                              className="w-12 h-12 rounded-md object-cover"
                                            />
                                          ) : (
                                            <div className="w-12 h-12 rounded-md bg-gray-100" />
                                          )}
                                          <div className="flex-1 min-w-0">
                                            <p className="text-sm font-medium text-gray-900 truncate">{product.name}</p>
                                            <p className="text-xs text-gray-500">
                                              Qty: {product.quantity}
                                              {product.unitPrice ? ` • ₱${product.unitPrice}` : ''}
                                            </p>
                                          </div>
                                        </div>
                                      );
                                    })}
                                  </div>
                                </div>
                              )}

                              {items && (
                                <div className="flex items-start gap-3">
                                  <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Items:</span>
                                  <span className="text-sm text-gray-900 font-medium">{items}</span>
                                </div>
                              )}
                              
                              {total && (
                                <div className="flex items-start gap-3">
                                  <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Total:</span>
                                  <span className="text-sm text-gray-900 font-semibold">₱{total}</span>
                                </div>
                              )}
                            </div>
                            
                            {/* Shop Information */}
                            {shopInfo && (
                              <div className="border-t border-gray-100 pt-3 mb-3">
                                <div className="flex items-center gap-3">
                                  {shopInfo.profile_photo ? (
                                    <img
                                      src={`/storage/${shopInfo.profile_photo}`}
                                      alt={shopInfo.business_name}
                                      className="w-10 h-10 rounded-full object-cover"
                                    />
                                  ) : (
                                    <div className="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center shrink-0">
                                      <svg className="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path
                                          strokeLinecap="round"
                                          strokeLinejoin="round"
                                          strokeWidth={2}
                                          d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"
                                        />
                                      </svg>
                                    </div>
                                  )}
                                  <div className="flex-1">
                                    <p className="text-sm font-semibold text-gray-900">{shopInfo.business_name}</p>
                                    {shopInfo.location && (
                                      <p className="text-xs text-gray-500">{shopInfo.location}</p>
                                    )}
                                  </div>
                                </div>
                              </div>
                            )}
                            
                            {/* Footer message */}
                            <div className="bg-blue-50 rounded-lg px-3 py-2.5 mb-4">
                              <p className="text-xs text-blue-900 leading-relaxed">
                                💡 Thank you for your order! We'll notify you once your items are ready for shipment.
                              </p>
                            </div>
                            
                            {/* View Button */}
                            <button
                              onClick={() => router.visit('/my-orders')}
                              className="w-full bg-white hover:bg-gray-50 text-black border border-gray-300 font-semibold py-2.5 px-4 rounded-lg transition-all duration-200 text-sm shadow-sm hover:shadow-md"
                            >
                              View Full Details
                            </button>
                          </div>
                        </div>
                      );
                    }
                    
                    // Repair Message Card
                    if (isRepairMessage) {
                      // Get additional data from conversation
                      const repairInfo = selectedConversation?.repairRequest;
                      const shopInfo = selectedConversation?.shopOwner;
                      
                      return (
                        <div key={message.id} className="flex justify-center my-6">
                          <div className="bg-white border border-gray-200 rounded-xl p-5 max-w-lg w-full shadow-sm hover:shadow-md transition-shadow">
                            {/* Header with Title and Status Badge */}
                            <div className="flex items-start justify-between mb-4">
                              <div className="flex-1">
                                <h3 className="text-base font-semibold text-gray-900 mb-1 flex items-center gap-2">
                                  <span>{content.includes('Accepted') ? 'Repair Order Accepted' : 'Repair Update'}</span>
                                </h3>
                                <p className="text-xs text-gray-500">
                                  {new Date(message.created_at).toLocaleString('en-US', {
                                    month: 'short',
                                    day: 'numeric',
                                    year: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit'
                                  })}
                                </p>
                              </div>
                              {status && (
                                <span className={`text-xs font-semibold px-2.5 py-1 rounded-full ${getStatusColor(status)}`}>
                                  {status}
                                </span>
                              )}
                            </div>
                            
                            {/* Repair Request Details Section */}
                            {repairInfo && (
                              <div className="bg-gray-50 rounded-lg p-4 mb-4 space-y-3">
                                <div className="flex items-start gap-3">
                                  <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Service:</span>
                                  <span className="text-sm text-gray-900 font-medium">{repairInfo.repair_type || repairType}</span>
                                </div>
                                
                                {(repairInfo.shoe_type || repairInfo.brand) && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Item:</span>
                                    <span className="text-sm text-gray-900">
                                      {repairInfo.brand && <span className="font-semibold">{repairInfo.brand}</span>}
                                      {repairInfo.brand && repairInfo.shoe_type && ' - '}
                                      {repairInfo.shoe_type}
                                    </span>
                                  </div>
                                )}
                                
                                {repairInfo.description && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Issue:</span>
                                    <span className="text-sm text-gray-700 leading-relaxed">{repairInfo.description}</span>
                                  </div>
                                )}
                                
                                {(deliveryType || repairInfo.delivery_method) && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Delivery:</span>
                                    <span className="text-sm text-gray-900 capitalize">{repairInfo.delivery_method || deliveryType}</span>
                                  </div>
                                )}
                                
                                {repairInfo.scheduled_dropoff_date && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Scheduled:</span>
                                    <span className="text-sm text-gray-900">
                                      {new Date(repairInfo.scheduled_dropoff_date).toLocaleDateString('en-US', {
                                        month: 'short',
                                        day: 'numeric',
                                        year: 'numeric',
                                        hour: '2-digit',
                                        minute: '2-digit'
                                      })}
                                    </span>
                                  </div>
                                )}
                                
                                {repairInfo.total && repairInfo.total > 0 && (
                                  <div className="flex items-start gap-3">
                                    <span className="text-gray-500 text-xs font-medium w-24 shrink-0">Estimate:</span>
                                    <span className="text-sm text-gray-900 font-semibold">₱{repairInfo.total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                                  </div>
                                )}
                              </div>
                            )}
                            
                            {/* Shop Information */}
                            {shopInfo && (
                              <div className="border-t border-gray-100 pt-3 mb-3">
                                <div className="flex items-center gap-3">
                                  {shopInfo.profile_photo ? (
                                    <img
                                      src={`/storage/${shopInfo.profile_photo}`}
                                      alt={shopInfo.business_name}
                                      className="w-10 h-10 rounded-full object-cover"
                                    />
                                  ) : (
                                    <div className="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center shrink-0">
                                      <svg className="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path
                                          strokeLinecap="round"
                                          strokeLinejoin="round"
                                          strokeWidth={2}
                                          d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"
                                        />
                                      </svg>
                                    </div>
                                  )}
                                  <div className="flex-1">
                                    <p className="text-sm font-semibold text-gray-900">{shopInfo.business_name}</p>
                                    {shopInfo.location && (
                                      <p className="text-xs text-gray-500">{shopInfo.location}</p>
                                    )}
                                  </div>
                                </div>
                              </div>
                            )}
                            
                            {/* Footer message */}
                            <div className="bg-blue-50 rounded-lg px-3 py-2.5 mb-4">
                              <p className="text-xs text-blue-900 leading-relaxed">
                                {content.includes('bring your item') 
                                  ? '💡 Please bring your item to our shop at your convenience.' 
                                  : '💡 We\'ll keep you updated on the progress of your repair.'}
                              </p>
                            </div>
                            
                            {/* View Button */}
                            <button
                              onClick={() => router.visit('/my-repairs')}
                              className="w-full bg-white hover:bg-gray-50 text-black border border-gray-300 font-semibold py-2.5 px-4 rounded-lg transition-all duration-200 text-sm shadow-sm hover:shadow-md"
                            >
                              View Full Details
                            </button>
                          </div>
                        </div>
                      );
                    }
                    
                    // Fallback for non-repair system messages
                    return (
                      <div key={message.id} className="flex justify-center my-4">
                        <div className="bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 max-w-md text-center shadow-sm">
                          <p className="text-xs text-gray-600 whitespace-pre-line leading-relaxed">
                            {message.content}
                          </p>
                          <span className="text-xs text-gray-400 mt-2 block">
                            {new Date(message.created_at).toLocaleTimeString()}
                          </span>
                        </div>
                      </div>
                    );
                  }
                  
                  // Regular messages
                  return (
                  <div
                    key={message.id}
                    className={`flex flex-col ${message.sender_type === 'customer' ? 'items-end' : 'items-start'}`}
                  >
                    {message.attachments && message.attachments.length > 0 ? (
                      <div>
                        <div className="flex flex-wrap gap-2 mb-2">
                          {message.attachments.map((img, idx) => (
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
                            className={
                              message.sender_type === 'customer'
                                ? 'bg-blue-500 text-white px-4 py-2 text-sm rounded-lg shadow-sm'
                                : 'bg-gray-100 text-gray-900 px-4 py-2 text-sm rounded-lg shadow-sm'
                            }
                          >
                            <p className="break-words text-sm leading-relaxed">{message.content}</p>
                          </div>
                        )}
                      </div>
                    ) : (
                      <div
                        className={`max-w-xs lg:max-w-md xl:max-w-lg ${
                          message.sender_type === 'customer'
                            ? 'bg-blue-500 text-white inline-block px-4 py-2 text-sm rounded-lg shadow-sm'
                            : 'bg-gray-100 text-gray-900 inline-block px-4 py-2 text-sm rounded-lg shadow-sm'
                        }`}
                      >
                        <p className="break-words text-sm leading-relaxed">{message.content}</p>
                      </div>
                    )}

                    <div className={`mt-2 ${message.sender_type === 'customer' ? 'text-right' : 'text-left'}`}>
                      <span className="text-xs text-gray-400" style={{ fontSize: '11px' }}>
                        {new Date(message.created_at).toLocaleTimeString()}
                      </span>
                    </div>
                  </div>
                  );
                })
              )}
              <div ref={messagesEndRef} />
            </div>

            <div className="border-t border-gray-200 bg-white px-6 py-4 rounded-b-2xl">
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

                <div className="flex-1 bg-gray-50 border border-gray-200 rounded-2xl px-4 py-3 flex items-center">
                  <input
                    type="text"
                    value={inputValue}
                    onChange={(e) => setInputValue(e.target.value)}
                    onKeyDown={handleKeyPress}
                    placeholder="Type a message"
                    disabled={!selectedConversation}
                    className="bg-transparent flex-1 text-sm text-gray-900 placeholder-gray-400 outline-none disabled:text-gray-400"
                  />
                </div>

                <button
                  onClick={handleSendMessage}
                  disabled={!selectedConversation || isSending || (!inputValue.trim() && selectedImages.length === 0)}
                  className={`p-2 rounded-full transition-colors flex-shrink-0 ${
                    selectedConversation && (inputValue.trim() || selectedImages.length > 0) && !isSending
                      ? 'text-gray-600 hover:text-gray-800'
                      : 'text-gray-300 cursor-not-allowed'
                  }`}
                >
                  <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M16.6915026,12.4744748 L3.50612381,13.2599618 C3.19218622,13.2599618 3.03521743,13.4170592 3.03521743,13.5741566 L1.15159189,20.0151496 C0.8376543,20.8006365 0.99,21.89 1.77946707,22.52 C2.40,22.99 3.50612381,23.1 4.13399899,22.8429026 L21.714504,14.0454487 C22.6563168,13.5741566 23.1272231,12.6315722 22.9702544,11.6889879 L4.13399899,1.16640469 C3.34915502,0.9015399 2.40734225,0.9015399 1.77946707,1.4742449 C0.994623095,2.0469499 0.837654326,3.1368016 1.15159189,3.9222884 L3.03521743,10.3632814 C3.03521743,10.5203788 3.19218622,10.6774762 3.50612381,10.6774762 L16.6915026,11.4629631 C16.6915026,11.4629631 17.1624089,11.4629631 17.1624089,12.0356681 C17.1624089,12.4744748 16.6915026,12.4744748 16.6915026,12.4744748 Z" />
                  </svg>
                </button>
              </div>
            </div>
          </div>
        </div>
      </main>

      {fullscreenImage && (
        <div
          className="fixed inset-0 backdrop-blur-md flex items-center justify-center z-50"
          onClick={() => setFullscreenImage(null)}
        >
          <button
            onClick={(event) => {
              event.stopPropagation();
              setFullscreenImage(null);
            }}
            className="absolute top-6 right-6 bg-white rounded-full w-10 h-10 flex items-center justify-center hover:bg-gray-100 transition-colors shadow-lg"
            title="Close"
          >
            <svg className="w-6 h-6 text-gray-800" fill="currentColor" viewBox="0 0 24 24">
              <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z" />
            </svg>
          </button>
          <img src={fullscreenImage} alt="Fullscreen view" className="max-w-[90vw] max-h-[90vh] object-contain" />
        </div>
      )}
    </div>
  );
};

export default Message;
