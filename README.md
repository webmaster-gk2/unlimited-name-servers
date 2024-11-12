# unlimited-name-servers

**unlimited-name-servers** is a script designed to add more than the default number of nameserver zones for domains created in cPanel and DirectAdmin. It works through a hook mechanism, making it simple and flexible to configure additional nameservers as needed for each domain.

### Features

- **Hook-Based Execution**: Automatically triggered to configure additional nameservers every time a domain is created or modified in cPanel or DirectAdmin.
- **Scalable Configuration**: Adds as many nameservers as desired per domain, without limitations on the number of nameserver zones.
- **Compatibility**: Supports both cPanel and DirectAdmin environments, ensuring smooth integration with these hosting control panels.

### How to Get Started

1. Clone the repository:
   ```bash
   git clone https://github.com/webmaster-gk2/unlimited-name-servers.git
   ```
2. Follow the setup instructions to add the hook to your cPanel or DirectAdmin server.
3. Configure the script to handle as many nameserver zones as needed for your domains.

### Contributing

Contributions are welcome! Please refer to the contribution guide to learn more about how you can help improve this project.

### License

This project is licensed under the [MIT License](LICENSE).
