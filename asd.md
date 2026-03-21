DEVELOPMENT OF AN INTEGRATED MANAGEMENT PLATFORM FOR SHOE RETAILER AND REPAIR SERVICES IN CAVITE WITH DECISION SUPPORT SYSTEM MOBILE APPLICATION AND VIRTUAL SHOWROOM

A Capstone Project
Presented to the Faculty of
Computer Studies Department
National College of Science and Technology
Dasmariñas, Cavite

In Partial Fulfillment
of the Requirements for the Degree
Bachelor of Science in Information Technology

By
BATO, FRANSZINE MIGUEL Y.
PARAGAS, JOHN DANIEL
YAMBAO, JOHN PAUL F.
APAWAN, BRENNAN A.
ALVAREZ, LESTER A.

March 2026 
Chapter 1
PROBLEM AND ITS BACKGROUND

Introduction
	In the era of rapid digital technology evolution, the retail business has experienced drastic change such as the use of digital systems for business operation improvement and customers’ needs enhancement. Small and medium enterprises (SMEs) including shoe resellers and shoe repairing shops, are the sectors where retail businesses are expected to adapt to the new market by upgrading the old business processes (Faiz et al., 2024; Gao et al., 2026).  However,  many of these SMEs (in Cavite,  for instance) still administrate their business manually,  and provide poor integrated customers’ services and lack of up-to-date information which lead to inefficiency and lost of opportunities.	
Digital transformation offers SMEs possibilities to improve their performance via efficiency improvements in data handling,  processes and interactions (Pan & Ahmad, 2025).  Integrated management information systems for example allow SMEs to integrate key business activities,  such as sales,  stock and service processes,  into one unified system which can then be monitored and managed more effectively (Nguyen & Tran, 2020).  Moreover, innovations enabled by digitalization of retail business models have been already found to bring important efficiency improvements and deliver new value propositions for the customers (Mostaghel et al., 2022). 
Furthermore,  the digital transformation can also be chosen as a key factor to evaluate customer satisfaction in retail industry.  Companies can take advantage of digital solutions and create more efficiently,  personalized and accurate services; thus affecting the customer satisfaction and retention (Ayof et al., 2025).  Current mobile technology is arguably the most dominant tool to facilitate real-time information access and enable decision making of the small-medium sized companies.  Nevertheless,  not everyone has integrated such technology in the business,  the mobile-enabled system would have an opportunity of improvement with pre-eminent accessibility,  argumention and control. 
Similarly,  many pioneering techniques such as 3D product visualization and interaction with the product elements online are making internet shopping more realistic and more transparent (Muzzi, 2024).  However,  most local shoe stores and shoemaker shops still remain without a unified comprehensive system with multilevel navigation.	
Thus in above situation the lack of a common system will restrict their scope of adapt to digital transformation and still have to respond to the market demands.  Solving this issue can greatly benefit to use the mobile supporting systems and unify the both system, which would lead to value added service, customer satisfaction and business performance enhancement.
Project Context
 	The researchers came up with ways on how to improve the management, customer engagement, and service efficiency of shoe retailers and repair service providers in Cavite. Many local shoe businesses experience challenges such as manual inventory tracking, unorganized repair scheduling, a lack of customer service transparency, and limited digital presence. These challenges frequently lead to service delays, record inaccuracies, and customer discontent. To mitigate these problems, the researchers suggested creating an integrated management platform. This platform would centralize retail and repair operations, simultaneously improving the customer experience through contemporary digital solutions.
The project involves an online platform and a mobile app, both of which are designed to make it easier for people in Cavite to find shoe retailers and shoe repair services.
The benefits to be derived from the project are to promote modernization of business and services of shoe retailers, while providing a personalized and immersive experience to shoe customers. The system addresses issues related to inventory and service tracking, real-time decision support system (DSS), and interactive shoe viewing experience to all shoe customers.
Inventory Management Module. Manages all aspects of product stock within the shop. Allows creation of new stock items with color and size options, management of existing stock, retrieval of current stock levels, viewing of stock movements, alarms on low stock levels, and generation of reordering requests to companies using a direct link method. The module is available to Inventory Manager and Shop Owner roles.
Repair Service Management Module. Repair Service Management module manages full repair process. Customer submit repair request including shoe type, brand, defect information and plan to fix shoe. System receives this information and allocates requests to available repair personnel according to defined order processes. It indicates the repair status between to do, received, fix, in fixing, waiting for parts, ready for pickup, ready for delivery. This module also provides repair packages, promotional packages, payment link with PayMongo, delivery method options.
Decision Support System(DSS) Module. This offers analytical decisions suggestions to shop owners and management. It presents charts on repair load and capacity, service sales amounts and pricing trends, product consumption, inventory turnover and customer demand in terms of visuals. Data is obtained from the live database. It uses ApexCharts to display these graphs. 
Virtual Showroom Module. This offers a new 3D product browsing experience for the customers. Developed with Three.js (http://threejs.org/), and WebGL, the virtual showroom displays a virtual shoe shop within a 3D environment and products are available on the shelves. Users can rotate the camera position, select a product to focus on, then rotate the shoe using a sequence of 360-degree images. Two display modes are available: Dusk and Night mode.
Finance Module. This module. which is fully integrated with the rest of the system, is used to record the financial operations of the shop. It comprises the maintenance of the chart of accounts, journal entries, invoices for retail or repair operations, expense account, budget management, recurring journal entries, tax rate setup, and financial reconciliation. It is based on double entry system and produces financial statements for management.
Human Resource (HR) Module. The HR module handles the manpower of the shop. It handles new employee join-in, employee profile updation, daily clock-in/clock-out monitoring with geo-fence verification, leave request and approval, salary report generation with component-wise calculation, overtime request handling and assessment.
Procurement Module. Uses an ordered procurement system to facilitate restocking of inventory. The user can create purchase requests that can be authorized by the Procurement Manager. Work creates the purchase order that is send to the supplier, and keep tracking about the purchased quantity, damaged, supplier efficiency
Customer Relationship Management (CRM) Module. The CRM module enables shop staff to keep customer records, which include notes on customer preferences, their products and repair service history, and special requests. It also accumulates the customer comments on products and repair services for the shop to keep track of their service quality, and respond if needed.
Messaging & Communication Module. This module provides an integrated real-time chat facility for Customers, Repairers, Shop staff and shop owners to chat in the context of an order or repair request. Conversations are “threaded” and display message reply chains and transferred between Shop staff members.
Audit and Activity Logging Module. All important actions happen inside the system are logged through use of Spatie Laravel Activity Log such as user login, profile update, role update, approve, ship, complete, push order status, update repair status, etc. For shop owner and super admin accountability. 
Mobile Application Module. The mobile application network is the customer interface to the platform for Android and iOS devices.  The customers will be able to browse shoe products,  submit order and shoe repair requests,  make repair request enquiries and updates to the shop, receive push notifications for order and shoe repair updates, and communicate with the shop through the integrated messaging module. The mobile app will connect to the Laravel back-end server over secure REST API endpoints with features and data parity with the web-based customer experience. The database of the system consists of over one hundred tables, categorized into logical groups for each module.
 Statement of the Problem 
	Shoe retailers and repair shop providers in Cavite did not use automated system but manual,  non-integrated methods in keeping track of their records on inventory, sales and repairs.  Such manual and non-integrated practices result to inaccurate reports on records,  inconvenience in monitoring, and slow workflow of repairs that would hamper the business owners’ decision making and further limit their business operations.  Furthermore, the absence of digital customer oriented features like interactive 3D viewing of the products inhibits customer engagement and accessibility of data on various foot wear products and services.

In response to the abovementioned issues, this research aims to provide an integrated management platform with a mobile-based decision support system and a 3D interactive product viewer for shoe shops and shoe repair shops in Cavite. This study seeks to answer the following questions:
   This study seeks to answer the following questions:
1.	What are the problems encountered by shoe retailers and shoe repair service providers in Cavite in terms of inventory, sales, and shoe repair services with the current system?
2.	How an integrated management platform to improve operating efficiency and data accuracy for footwear retailing and shoe repairs?
3.	In what ways can a decision support system, implemented on a hand held device, provide assistance to a business owner in managerial decision making?
4.	In what way interactive 3D product visualization can improve the customer accessibility,  involvement and shopping experience?
5.	How efficient is the proposed system over the current processes of shoe retailers and repair service providers of the Cavite area in terms of their overall management and customer service?

Objectives of the Study
The purpose of this study is to come up with an integrated management system with a mobile decision support system and interactive 3D product viewing to improve the efficiency of operation and data, provide more high quality service to customers for shoe retailers and shoe repair service providers in Cavite
1.	Explain the problems shoemenders and shoe repair shop owners in Cavite have experienced in operations such as inventory, sales, and repair services with current systems.
2.	Developing an integrated platform for managing the management of stock, the sale of stock and timely track of repair service.
3.	Mobile business decision support systems that helps to make out analytical decisions reports and business intelligence.
4.	Create a 3D product viewing feature, that is interactive, that allows consumers to virtually look at the product offerings.
5.	Go through how efficiently the system will be able to manage, how accurately the data will be maintained, how easy the system is to be used and how satisfied the customers will be.
Scope and Limitation of the Study
This study focuses on the development of an integrated mobile application  system for shoe business owners and repair service providers in Cavite. The system merges basic business functionalities like inventory management, sales and transactions monitoring, repair service tracking,  mobile-based decision support system (DSS),  interactive 3D product viewing,  and user management. The inventory management system helps business owners in recording,  monitoring, and updating of stock levels of footwear products the sales and transaction management system aids in sales transactions,  Order history, and basic sales reporting. The repair service management system helps business owners keep track of customer information,  repair type, repair status, and estimated repair period.  The DSS produces reports and histogram graphs of business sales and repair demand trends, able to give summarized analytics of business performance. The interactive 3D viewing system allows customers to view available footwear products and services with a tablet PC by manipulating it, and accessing detailed product information. User management system provides different access levels and feedback collection for admin and customer. The selected shoe retailers and repair service providers in Cavite are the beneficiaries of this study system evaluation was based on system operation, data accuracy,  ease of use, and customer satisfaction.
However, despite the added value it brings, the study has its own limitations and restrictions as well. The system is purely crafted for shoe retail and shoe repair transactions and doesn‘t encompass other products like bags, apparels, accessories, among others. The decision support system is purely analytical, providing only some basic forecast, with no utilization of complex decision support system which employs artificial intelligence, machine learning, and predicts the choice of the user. The 3D view of the products only renders 3D models that have been programmed into the system, with only the basic controls for interaction; no real-time augmented reality, dynamic rendering, and environmental simulation are used. The system also requires internet connection and mobile connectivity for real-time updates and mobile network, which could inconvenient some customers. Moreover, the study is limited to few selected enterprise locations in Cavite thus, the results can‘t be generalized to other places. The online payment transfer system can be integrated with other means of payment and can be linked and synchronized with several logistics system in the market. The system does not employ any advanced cyber security standards and practices; it merely offers protection from unauthorized access by simple authentication and access control.
Significance of the Study
This study is significant as it contributes to the digital transformation of shoe retailers and repair service providers in Cavite by providing an integrated management platform that enhances operational efficiency, decision-making, and customer engagement. The results of this study are beneficial to the following stakeholders:

Shoe Retailers and Repair Service Providers.
Business owners and service managers will benefit from the system through improved inventory control, organized repair service tracking, and centralized sales management. The mobile-based decision support system enables them to access real-time business insights, analyze sales trends, monitor service demand, and make informed decisions that can improve profitability and service efficiency.
Customers. Customers will benefit from increased accessibility and convenience through the mobile application and interactive 3D product viewing. The system allows customers to explore footwear products, view service offerings, track repair status, and access detailed product information without visiting the store physically. This feature enhances customer engagement, transparency, and the overall service experience.
Local Small and Medium Enterprises (SMEs). The study supports the digital empowerment of local SMEs in Cavite by providing a cost-effective and scalable solution tailored to their operational needs. The platform encourages the adoption of technology among small businesses, helping them remain competitive in an increasingly digital marketplace.
Researchers and Future Developers. This study serves as a reference for future researchers and system developers interested in integrated management systems, decision support systems, mobile applications, and interactive 3D product viewing. The findings, design framework, and implementation approach may be used as a foundation for further enhancements, such as advanced analytics, augmented reality features, or expansion to other retail sectors.
Academic Institutions.The study contributes to academic research in information technology by demonstrating the practical application of system development methodologies in addressing real-world business problems. It provides students and educators with a concrete example of how mobile-based integrated systems can be designed and evaluated in a local industry context.

Definition of Terms. 
This study operationally defined the following:
3D Product Viewing. A feature of the system that allows customers to explore footwear products and services using interactive three-dimensional models with rotation, zoom, and basic controls through the mobile application.
Decision Support System (DSS). A system component that analyzes stored business data such as sales, inventory, and repair services, to generate reports and visual summaries that assist business owners in making informed decisions.
Integrated Management Platform. A centralized digital system that combines inventory management, sales tracking, repair service management, decision support, and customer interaction into a single mobile-based application.
Inventory Management. The process of recording, monitoring, and updating product stock information, including item details, quantity, and availability, using the developed system.
Mobile Application. A software application developed for mobile devices that provides access to the integrated management platform, allowing users to manage operations and interact with system features anytime and anywhere.
Operational Efficiency. The ability of shoe retailers and repair service providers to perform daily business operations accurately and promptly through the use of an automated and integrated system.
Repair Service Management. A system function that records and tracks repair requests, customer details, service types, repair status, and estimated completion dates.
Sales Monitoring. The process of tracking sales transactions and generating sales-related data used for reporting and decision-making
Small and Medium Enterprises (SMEs). Independently owned local businesses with limited operational scale, particularly shoe retailers and repair shops targeted in this study.
User Management. A system feature that controls user access by assigning roles such as administrator and customer, ensuring appropriate use of system functionalities.
Virtual Product Browsing. The ability of customers to explore products and services digitally through images, descriptions, pricing, availability, and 3D visualization within the mobile application.
Audit Trail. A chronological record of user and system activities such as login, profile updates, approval actions, and status changes used for transparency and accountability.
Chart of Accounts. A structured list of financial account categories used by the finance module to classify and organize business transactions.
Double-Entry Accounting. An accounting method where every transaction is recorded with corresponding debit and credit entries to maintain financial accuracy and balance.
Geofencing Verification. A location-based control in the HR module that validates whether employee clock-in and clock-out actions occur within authorized workplace boundaries.
Low Stock Alert. A notification generated by the inventory module when item quantity reaches a predefined threshold, prompting replenishment actions.
Procurement Workflow. A step-by-step purchasing process that starts from purchase requests and approvals and continues to purchase order creation, supplier coordination, and delivery tracking.
Real-Time Messaging. An in-system communication feature that enables immediate exchange of messages among customers, shop staff, repairers, and shop owners.
Repair Status Tracking. The process of monitoring each repair request through defined stages such as to do, in fixing, waiting for parts, ready for pickup, and completed.
REST API. A secure set of web service endpoints used by the mobile application to send and retrieve data from the Laravel back-end server.
Role-Based Access Control (RBAC). A security mechanism that grants system permissions based on user roles to ensure that users only access features relevant to their responsibilities.
Virtual Showroom. A digital 3D shop environment developed with Three.js and WebGL where customers can navigate products and inspect footwear in an interactive way.
 
CHAPTER 2
REVIEW OF RELATED LITERATURE AND STUDIES
Local Literature
According to Samaya Dharmaraj (2025), the Department of Science and Technology (DOST) MIMAROPA collaborated with regional universities to develop digital solutions aimed at helping micro, small, and medium enterprises (MSMEs) modernize their operations. These solutions included inventory processing systems, sales and inventory management systems, and web-based logistics platforms. The systems were designed to automate inventory tracking, sales recording, and stock monitoring. As a result, businesses in the region achieved greater operational efficiency, minimized errors, and gained access to real-time data for better decision-making.
According to Jefferson S. Flores (2025), the implementation of e-commerce has brought notable changes to small and medium enterprises (SMEs) in the Second District of Albay by widening their customer reach, increasing income opportunities, and enhancing cost efficiency. The study explains that incorporating digital sales platforms and online channels into existing business operations allows local SMEs to respond more effectively to evolving consumer preferences and economic trends.





According to Wilson Cordova et al. (2025), digital innovation significantly contributes to entrepreneurial success in the Philippine MSME sector by identifying both the obstacles and potential benefits of digital adoption. The study found that although many MSMEs rely on basic digital tools such as social media marketing and online payment systems, the adoption of more advanced technologies, including cloud computing and integrated management systems, remains limited. This gap is primarily due to financial constraints, lack of technical expertise, and inadequate infrastructure support.
According to Julie Anne S. Bangisan et al. (2023), a study examining the level of e-commerce application among MSMEs in San Jose, Occidental Mindoro found that technology adoption varies based on organizational, environmental, and technological factors. The findings indicated that enterprises that actively utilize digital systems demonstrate more efficient organizational workflows and gain access to broader market opportunities.
According to Jane Anne B. Gaborno et al. (2025), micro business owners in Guimaras practice inventory management strategies centered on proper stock categorization, clear documentation, and timely fulfillment of customer demand. While the research employed a descriptive approach, it indicated that well-managed inventory systems are linked to increased customer satisfaction and better overall operational results.





   Foreign Literature
According to Evangelin et al. (2025), Augmented Reality is transforming the retail landscape by driving higher conversion rates and reducing cart abandonment. By offering immersive tools that allow shoppers to visualize and personalize products virtually, brands can increase buyer confidence and foster deeper emotional connections. Ultimately, these digital interactions bridge the gap between browsing and buying, leading to measurable growth in sales and engagement
According to Brown, Johnson, and Wilson (2025), The study posits that e-commerce technologies have revolutionized retail supply chain management by significantly elevating operational efficiency. Through the integration of real-time inventory tracking, predictive analytics, and automated coordination across the logistics network, digital platforms enable retailers to optimize inventory levels and enhance fulfillment processes. Consequently, these innovations allow for a degree of market responsiveness and agility that far surpasses traditional retail operations.
According to Fan (2025), the combined use of Augmented Reality (AR) and Virtual Reality (VR) is becoming more widespread in the retail industry as a means of connecting physical and digital environments. The research indicates that these technologies enhance consumer interaction with products and strengthen marketing effectiveness. By recreating real-world experiences through immersive digital platforms, AR and VR positively affect purchase intention and increase overall user engagement.

According to Kovács and Keresztes (2024), emerging digital technologies, particularly Augmented Reality (AR) applications, have reshaped e-commerce by improving the quality of online shopping experiences. These tools enable customers to virtually try on clothing and explore products through enhanced interactive visualization features. Based on their qualitative research among fashion consumers, the findings indicate that AR enhances visual appeal, shopping convenience, and user engagement, which in turn positively influences purchase decisions and encourages the continued adoption of innovative retail technologies.
According to Poushneh (2021), immersive 3D technologies and virtual product presentation features substantially enhance online shopping experiences by strengthening customer trust, improving perceived product quality, and increasing purchase intention. The study explains that interactive 3D environments minimize uncertainty by enabling consumers to closely inspect products in a way that resembles an in-store experience. This heightened level of interaction leads to greater engagement and improved confidence in purchasing decisions.








Local Studies
According to Soliveres, Herrera, and Cedillo (2024), a descriptive study examined the inventory management practices of small-scale pharmacies in selected towns in Cavite, Philippines. The study found that these pharmacies use structured methods such as Economic Order Quantity (EOQ), FSN analysis, and First Expiry First Out (FEFO) to maintain product availability and prevent stock shortages. It also showed that inventory management practices greatly influence customer satisfaction and operational performance, especially in pharmacies that still rely heavily on manual processes without digital systems.
According to Camposano, Dalogdogan, Euste, and Venoza (2024), a study published in the International Journal of Research and Innovation in Social Science examined effective approaches for collecting, organizing, and sharing ideas among SMEs in the Philippines. The findings showed that structured management practices, including the use of digital strategies and knowledge-sharing systems, positively influence business performance. These practices improve internal processes and enable enterprises to address operational challenges more efficiently.
According to the Polytechnic University of the Philippines research (2024), a comparative study analyzed the adoption of Internet of Things (IoT) technologies and their impact on supply chain management among SMEs in the Philippines and selected Asian countries. The findings showed that IoT applications improve real-time data exchange, inventory monitoring, and coordination within supply chain operations. However, the level of adoption differs across regions due to economic limitations and infrastructure challenges.	
According to ATIFTAP (2025), researchers developed a web-based application called Simflow to improve inventory, sales, and ordering processes for a local Filipino food producer. The system was built using Laravel and the Model–View–Controller (MVC) architecture to automate business activities that were previously handled manually. As a result, the application enhanced sales monitoring and inventory management. System evaluation showed an estimated 85% performance effectiveness, indicating its ability to improve overall operational efficiency.
According to a study on micro-business inventory practices in Buenavista, Guimaras (2024), micro-business owners utilize different inventory management approaches, including stock categorization and transparent reporting methods. The study revealed that many enterprises still depend on conventional or informal practices, reflecting limited adoption of technology-based systems. It further showed that the effectiveness of inventory management is influenced by factors such as the owner’s educational attainment, financial capacity, and scale of operations.
Foreign Studies
According to Shah J. Miah et al. (2022), the study examined the influence of business analytics and decision support systems (DSS) on e-commerce operations among small and medium enterprises (SMEs). Through a descriptive analysis, the researchers evaluated different applications of DSS and analytics tools within SME e-commerce activities to assess their impact on business performance. The findings revealed that integrating decision support systems with e-commerce platforms strengthens data-based decision-making, enhances sales monitoring, and improves operational planning in SMEs.
According to Mustafa Salimi and Chandrasekar K. S. (2025), a study utilized Partial Least Squares Structural Equation Modeling (PLS-SEM) to assess the impact of digital transformation on the performance of small and medium enterprises in Kerala, India. Using survey data collected from 400 SME employees, the research found that factors such as digital preparedness, employee participation, and organizational flexibility significantly contribute to the success of digital transformation efforts. The results further showed that effective digital transformation enhances overall organizational performance, increases competitiveness, and improves the ability of enterprises to adjust to changing market conditions.
According to Wu, Botella-Carrubi, and Blanco-González-Tejero (2024), an empirical study explored how digital marketing strategies affect the performance of Taiwanese SMEs. Based on survey data from 148 companies, the findings showed that firms with well-developed digital marketing approaches—supported by strong internal capabilities such as innovation and managerial competence—tend to achieve better organizational results. These outcomes include greater market share, quicker response to market demand changes, and higher sales growth. The study also concluded that the positive impact of digital strategies on performance is stronger when organizations demonstrate high agility and effective management practices.
According to Bindeeba et al. (2025), empirical evidence indicates that the adoption of digital business systems, such as analytics tools and real-time data platforms, allows SMEs to improve operational efficiency and performance. The study explains that these technologies help lower costs and reduce waste by ensuring that business operations are aligned with strategic objectives. It further emphasizes that digital transformation supports long-term sustainability, particularly when organizations have access to reliable operational information and sufficient financial support.
According to Jang (2023), the study examined consumer visual behavior in an immersive virtual retail environment by analyzing users’ gaze patterns and attention levels. The findings showed that consumers with strong purchasing intentions demonstrated greater visual focus on products within simulated virtual stores. The research suggests that immersive technologies, including 3D and virtual simulations, significantly enhance visual engagement and contribute to higher levels of consumer satisfaction.
Synthesis
The reviewed foreign and local literature and studies show that digital transformation is essential for improving the operations of small and medium enterprises, especially in retail and service industries. Traditional manual systems cause inefficiencies, data inaccuracies, and poor customer engagement, which are the same problems faced by shoe retailers and repair service providers in Cavite.
Research confirms that integrated management systems, mobile applications, and decision support systems help businesses manage inventory, sales, and services more efficiently. These technologies also support better decision-making by providing real-time data and analytical reports.
Studies on virtual and 3D product viewing further show that interactive digital tools increase customer interest, satisfaction, and confidence in purchasing. This supports the inclusion of a 3D virtual showroom in the proposed system.
Overall, the literature and studies validate the need for the proposed integrated mobile platform. They prove that combining operational management, decision support, and interactive product visualization can significantly enhance efficiency, accuracy, and customer engagement for shoe retailers and repair services in Cavite.

CHAPTER 3
TECHNICAL BACKGROUND
Organizational Chart
This chapter presents the technical foundation of the proposed integrated management platform for shoe retailers and repair services in Cavite. It outlines the system’s organizational structure, flow, and architecture, showing how the mobile application, decision support system, and virtual showroom work together to support business operations and customer interaction.









REFERENCES
Pan, J., & Ahmad, N. H. (2025). Technological innovation and SME performance: Mediating roles of digital transformation & resource integration, moderating roles of strategic orientation & market dynamics. International Journal of Management and Sustainability, 14(4), 1203–1218. https://doi.org/10.18488/11.v14i4.4645
Ayof, M. N., Yusof, S. W. M., Yusof, M. N. a. M. M., & Ortega, R. T. (2025). The role of digital transformation in shaping customer satisfaction in retailing. International Journal of Research and Innovation in Social Science, IX(IX), 9021–9030. https://doi.org/10.47772/ijriss.2025.909000742
Faiz, F., Le, V., & Masli, E. K. (2024). Determinants of digital technology adoption in innovative SMEs. Journal of Innovation & Knowledge, 9(4), 100610. https://doi.org/10.1016/j.jik.2024.100610
Gao, S., Teh, P., & Ho, H. H. P. (2026). Digital transformation and innovation in small and medium enterprises (SMEs): a systematic review and future research agenda. Cogent Business & Management, 13(1). https://doi.org/10.1080/23311975.2026.2612775
Nguyen, V. T., & Tran, L. M. (2020). Integrated management information systems for small businesses. Journal of Information Technology Management, 31(2), 56–70. https://doi.org/10.xxxx/jitm.2020.031
Mostaghel, R., Oghazi, P., Parida, V., & Sohrabpour, V. (2022). Digitalization driven retail business model innovation: Evaluation of past and avenues for future research trends. Journal of Business Research, 146, 134–145. https://doi.org/10.1016/j.jbusres.2022.03.072
Books
A.	Journals and Periodicals
Bangisan, J. A. S., et al. (2023). Level of e-commerce application by micro, small, and medium enterprises (MSMEs) in San Jose, Occidental Mindoro. International Journal of Research and Innovation in Social Science (IJRISS), 7(4), 987–998. https://www.researchgate.net/publication/370253312
Brown, T., Johnson, R., & Wilson, P. (2025). Influence of e-commerce technologies on supply chain management in retail. Journal of Retail Technology and Supply Chain Systems, 18(2), 145–162.
https://www.researchgate.net/publication/382321378
Camposano, A. A., Dalogdogan, K. M., Euste, R. M., & Venoza, J. B. (2024). Unlocking SME success: Successful strategy in gathering, organizing, and sharing ideas in an organization. International Journal of Research and Innovation in Social Science (IJRISS), 8(8), 1748–1767.
https://rsisinternational.org/journals/ijriss/articles/unlocking-sme-success-successful-strategy-in-gathering-organizing-and-sharing-ideas-in-an-organization/
Elajas, D. R., et al. (2024). Improving supply chain management: A comparative study on Internet of Things adoption in SMEs of the Philippines and Asian countries. International Journal of Research and Innovation in Social Science (IJRISS), 8(8), 1091–1112.
https://rsisinternational.org/journals/ijriss/articles/improving-supply-chain-management-a-comparative-study-on-internet-of-things-adoption-in-smes-of-philippines-and-asian-countries/
Evangelin, A., et al. (2025). Augmented reality in retail: Elevating customer engagement and driving sales. International Journal of Retail and Digital Commerce, 12(1), 33–48.
https://www.researchgate.net/publication/393686212
Fan, X. (2025). Augmented reality and virtual reality in retail: Bridging physical and digital experiences. Sustainability, 17(2), 728.
https://www.mdpi.com/2071-1050/17/2/728
Flores, J. S. (2025). The impact of e-commerce on small and medium enterprises in the second district of Albay. International Journal of Research and Innovation in Social Science (IJRISS), 9(1), 2110–2121.
https://rsisinternational.org/journals/ijriss/articles/the-impact-of-e-commerce-on-small-and-medium-enterprises-in-the-second-district-of-albay/
Gaborno, J. A. B., et al. (2025). Micro-business owners’ inventory management practices. International Journal of Research and Innovation in Social Science (IJRISS), 9(1), 4550–4559.
https://rsisinternational.org/journals/ijriss/articles/micro-business-owners-inventory-management-practices/
Jang, H. (2023). Consumer visual behavior in immersive virtual retail environments: An eye-tracking study. Fashion and Textiles, 10(1), 1–19.
https://link.springer.com/article/10.1186/s40691-023-00345-9
Kovács, G., & Keresztes, G. (2024). The impact of augmented reality applications on fashion e-commerce consumer experience. Virtual Economics, 11(3), 56–72.
https://www.mdpi.com/2227-9709/11/3/56
Poushneh, A. (2021). Augmented reality and virtual reality in retail: The role of immersive product experience on purchase intention. Journal of Retailing and Consumer Services, 61, 102–576.
https://doi.org/10.1016/j.jretconser.2021.102576

Salimi, M., & Chandrasekar, K. S. (2025). Adopting digital transformation: An empirical study on how digital transformation shapes organizational success. Journal of Business Transformation and Technology, 9(1), 101–120.
https://www.researchgate.net/publication/400171429
Soliveres, V. L., Herrera, A. C., & Cedillo, A. K. (2024). Inventory management practices of small-scale pharmacies in selected towns in Cavite. Logistics and Operations Management Research, 3(1), 42–56.
https://journals.researchsynergypress.com/index.php/lomr/article/view/2194
Wu, W., Botella-Carrubi, D., & Blanco-González-Tejero, C. (2024). Digital marketing strategy and performance in Taiwanese SMEs. Technological Forecasting and Social Change, 197, 122873.
https://www.sciencedirect.com/science/article/abs/pii/S0040162523008272


B.	Thesis/Dissertation
Cordova, W., Santos, J., & Dela Cruz, M. (2025). Digital innovation and entrepreneurial success in the Philippine MSME sector: Challenges and opportunities (Undergraduate thesis). Philippines.
https://www.researchgate.net/publication/395198618
Miah, S. J., et al. (2022). Business analytics and decision support systems in SME e-commerce. arXiv preprint.
https://arxiv.org/abs/2212.00016
C.	Online Resources
Dharmaraj, S. (2025, March 28). The Philippines: Digital solutions for MSMEs in MIMAROPA. OpenGov Asia.
https://archive.opengovasia.com/2025/03/28/the-philippines-digital-solutions-for-msmes-in-mimaropa/






